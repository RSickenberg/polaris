<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Dashboard;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Polaris\Dashboard\Controller\HomeController;
use Polaris\Dashboard\Service\VehicleCardService;
use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseAssessment;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\Tolerance;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Lease\Service\LeaseReturnService;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\Money;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[CoversClass(HomeController::class)]
#[CoversClass(VehicleCardService::class)]
final class HomeControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::mockTime('2026-07-02 12:00:00 UTC');
        $this->client = self::createClient();
        self::signIn($this->client);
    }

    public function testWithoutAVehicleItShowsAnEmptyState(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Your vehicles');
        self::assertSelectorTextContains('main', 'No vehicle yet.');
    }

    public function testTheSidebarAlwaysLinksToTheVehicleList(): void
    {
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('[data-slot=sidebar] a[href="/"][data-active=true]', 'My vehicles');

        $this->client->request('GET', '/settings');
        self::assertSelectorTextContains('[data-slot=sidebar] a[href="/"][data-active=false]', 'My vehicles');
        // No vehicle page, no other links, so nothing to separate.
        self::assertSelectorNotExists('[data-slot=sidebar] [data-slot=sidebar-separator]');
    }

    public function testItListsTheVehiclesByNameWithTheirLinks(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $second = new Vehicle(new Vin('5YJ3E7EB2NF000081'), 'Model Y', self::createUser());
        $first = new Vehicle(new Vin('5YJ3E7EB2NF000082'), 'Cybertruck', self::createUser());
        $entityManager->persist($second);
        $entityManager->persist($first);
        $entityManager->flush();

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Your vehicles');
        $names = $this->client->getCrawler()->filter('main h2')->each(static fn ($heading): string => $heading->text());
        self::assertSame(['Cybertruck', 'Model Y'], $names);
        self::assertSelectorExists(\sprintf('main a[href="/vehicles/%s"]', $first->getId()->toBase32()));
        self::assertSelectorExists(\sprintf('main a[href="/vehicles/%s/readings"]', $first->getId()->toBase32()));
        self::assertSelectorExists(\sprintf('main a[href="/vehicles/%s/lease"]', $first->getId()->toBase32()));
    }

    public function testReturnedVehiclesComeLastWithAPillAndAMutedCard(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $owner = self::createUser();
        // By name the returned one would be first.
        $returned = new Vehicle(new Vin('5YJ3E7EB2NF000083'), 'Aardvark', $owner);
        $running = new Vehicle(new Vin('5YJ3E7EB2NF000084'), 'Zebra', $owner);
        $entityManager->persist($returned);
        $entityManager->persist($running);
        $entityManager->persist(new LeaseContract(
            $returned,
            LeaseTerm::fromCalendarDates(
                CarbonImmutable::parse('2020-01-01', 'UTC'),
                CarbonImmutable::parse('2021-01-01', 'UTC'),
                EndDateConvention::Exclusive,
            ),
            Allowance::ofTotal(Distance::fromKilometres(15_000)),
            Distance::fromKilometres(0),
            Money::fromDecimal('0.12', Currency::CHF),
            Tolerance::fromPercent(0),
        ));
        $entityManager->flush();
        self::getContainer()
            ->get(LeaseReturnService::class)
            ->markReturned($returned, new \DateTimeImmutable('2021-01-02'));

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Zebra', 'Aardvark'],
            $this->client->getCrawler()->filter('main h2')->each(static fn ($node): string => $node->text()),
        );
        self::assertSelectorCount(1, '[data-returned-badge]');
        self::assertSelectorTextContains('[data-returned=true] [data-returned-badge]', 'Returned');
        self::assertSelectorNotExists('[data-returned=false] [data-returned-badge]');

        $this->client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);
        self::assertSelectorTextContains('[data-returned-badge]', 'Rendu');
    }

    /**
     * @return iterable<string, array{int, int, string, string}>
     */
    public static function riskLevels(): iterable
    {
        // km driven after 182 days of a 15 000 km, one year lease, tolerance in percent.
        yield 'ok' => [5_000, 0, 'ok', 'OK'];
        yield 'watch' => [7_000, 10, 'watch', 'Watch'];
        yield 'over' => [10_000, 0, 'over', 'Over the allowance'];
    }

    #[DataProvider('riskLevels')]
    public function testARunningLeaseShowsItsFiguresAndTheRiskLevelWithIconAndWords(
        int $driven,
        int $tolerance,
        string $level,
        string $words,
    ): void {
        $vehicle = $this->vehicle('5YJ3E7EB2NF000091', 'Model 3');
        $this->lease($vehicle, '2026-01-01', 15_000, $tolerance);
        $this->reading($vehicle, '2026-07-02 11:00:00', $driven);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $card = '[data-vehicle-card][data-state=active]';
        $badge = $card . ' [data-risk=' . $level . ']';
        self::assertSelectorTextContains($badge, $words);
        self::assertSelectorExists($badge . ' svg[aria-hidden=true]');
        self::assertSelectorExists($card . ' [role=progressbar]');
        $text = preg_replace('/\s+/u', ' ', $this->client->getCrawler()->filter($card)->text()) ?? '';
        self::assertStringContainsString(number_format($driven) . ' of 15,000 km driven', $text);
        self::assertStringContainsString('Projected at lease end', $text);
        // From 2 July 12:00 to 1 January 2027: 5 whole months (to 2 December), then 30 days.
        self::assertStringContainsString('Time left 5 months', $text);
        self::assertStringContainsString('Last reading Today at 11:00 AM', $text);
        self::assertSelectorNotExists($card . ' [data-card-state-text]');
    }

    public function testTheFiguresAreTheOnesOfTheDashboard(): void
    {
        $vehicle = $this->vehicle('5YJ3E7EB2NF000092', 'Model 3');
        $this->lease($vehicle, '2026-01-01', 15_000, 0);
        $this->reading($vehicle, '2026-07-02 11:00:00', 7_000);

        $assessment = self::getContainer()->get(LeaseTermsService::class)->assess($vehicle);
        self::assertInstanceOf(LeaseAssessment::class, $assessment);

        $this->client->request('GET', '/');

        $projected = $this->client->getCrawler()->filter('[data-card-figure=projected] dd')->text();
        self::assertSame(
            number_format($assessment->projection->projectedAtEnd->metres() / 1000) . ' km',
            preg_replace('/\s+/u', ' ', $projected),
        );
    }

    public function testInFrenchTheFiguresFollowTheSwissConventionAndTheWordsAreFrench(): void
    {
        $vehicle = $this->vehicle('5YJ3E7EB2NF000093', 'Model 3');
        $this->lease($vehicle, '2026-01-01', 15_000, 0);
        $this->reading($vehicle, '2026-07-02 11:00:00', 7_000);

        $this->client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);

        $text = preg_replace('/\s+/u', ' ', $this->client->getCrawler()->filter('[data-vehicle-card]')->text()) ?? '';
        self::assertStringContainsString('7’000 sur 15’000 km parcourus', $text);
        self::assertStringContainsString('Temps restant 5 mois', $text);
        self::assertStringContainsString('Dernier relevé', $text);
        self::assertSelectorTextContains('[data-vehicle-card] [data-risk=ok]', 'OK');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function stateCards(): iterable
    {
        yield 'no lease' => ['no_lease', 'No lease terms yet.'];
        yield 'no reading' => ['no_reading', 'No reading since the lease start.'];
        yield 'ended' => ['ended', 'The lease has ended.'];
        yield 'returned' => ['returned', 'The car was handed back to the lessor.'];
    }

    #[DataProvider('stateCards')]
    public function testAnyOtherStateShowsOneSentenceInsteadOfFigures(string $state, string $sentence): void
    {
        $vehicle = $this->vehicle('5YJ3E7EB2NF000094', 'Model 3');
        match ($state) {
            'no_lease' => null,
            'no_reading' => $this->lease($vehicle, '2026-01-01', 15_000, 0),
            'ended', 'returned' => $this->endedLease($vehicle, 'returned' === $state),
            default => throw new \LogicException($state),
        };

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $card = '[data-vehicle-card][data-state=' . $state . ']';
        self::assertSelectorTextContains($card . ' [data-card-state-text]', $sentence);
        self::assertSelectorNotExists($card . ' [role=progressbar]');
        self::assertSelectorNotExists($card . ' [data-card-figure]');
        self::assertSelectorNotExists($card . ' [data-risk]');
        // The VIN and the three links stay.
        self::assertSelectorTextContains($card, '5YJ3E7EB2NF000094');
        self::assertSelectorCount(3, $card . ' a[data-slot=button]');
    }

    private function vehicle(string $vin, string $name): Vehicle
    {
        $vehicle = new Vehicle(new Vin($vin), $name, self::createUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($vehicle);
        $entityManager->flush();

        return $vehicle;
    }

    private function lease(Vehicle $vehicle, string $start, int $allowanceKm, int $tolerancePercent): void
    {
        $start = CarbonImmutable::parse($start, 'UTC');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new LeaseContract(
            $vehicle,
            LeaseTerm::fromCalendarDates($start, $start->addMonthsNoOverflow(12), EndDateConvention::Exclusive),
            Allowance::ofTotal(Distance::fromKilometres($allowanceKm)),
            Distance::fromKilometres(0),
            Money::fromDecimal('0.12', Currency::CHF),
            Tolerance::fromPercent($tolerancePercent),
        ));
        $entityManager->flush();
    }

    private function endedLease(Vehicle $vehicle, bool $returned): void
    {
        $this->lease($vehicle, '2025-01-01', 15_000, 0);
        $this->reading($vehicle, '2025-12-30 10:00:00', 14_000);
        if ($returned) {
            self::getContainer()
                ->get(LeaseReturnService::class)
                ->markReturned($vehicle, new \DateTimeImmutable('2026-01-02'));
        }
    }

    private function reading(Vehicle $vehicle, string $at, int $km): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(
            OdometerReading::manual($vehicle, CarbonImmutable::parse($at, 'UTC'), Distance::fromKilometres($km))
        );
        $entityManager->flush();
    }
}
