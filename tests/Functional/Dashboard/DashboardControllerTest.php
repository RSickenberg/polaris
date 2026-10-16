<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Dashboard;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Polaris\Dashboard\Controller\DashboardController;
use Polaris\Dashboard\Service\DashboardService;
use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseAssessment;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\Tolerance;
use Polaris\Lease\Entity\LeaseContract;
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

#[CoversClass(DashboardController::class)]
#[CoversClass(DashboardService::class)]
final class DashboardControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use SignsIn;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        self::mockTime('2026-07-02 12:00:00 UTC');
        $this->client = self::createClient();
        self::signIn($this->client);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000061'), 'Model 3', self::createUser());
        $this->entityManager->persist($this->vehicle);
        $this->entityManager->flush();
    }

    public function testUnknownVehicleIsNotFound(): void
    {
        $this->client->request('GET', '/vehicles/01JZZZZZZZZZZZZZZZZZZZZZZZ');

        self::assertResponseStatusCodeSame(404);
    }

    public function testWithoutALeaseTheDashboardPointsToTheLeaseSettings(): void
    {
        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Dashboard, Model 3');
        self::assertSelectorTextContains('main', 'Add the lease terms of this vehicle');
        self::assertSelectorExists(\sprintf('main a[data-slot=button][href="/vehicles/%s/lease"]', $this->id()));
        self::assertSelectorNotExists('[role=progressbar]');
    }

    public function testWithoutAReadingAfterTheLeaseStartTheDashboardPointsToTheReadings(): void
    {
        $this->lease('2026-01-01', 12, 15_000, 2);
        // Before the lease start: it does not count.
        $this->reading('2025-12-01 10:00:00', 40);

        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'No odometer reading after the lease start yet.');
        self::assertSelectorExists(\sprintf('main a[data-slot=button][href="/vehicles/%s/readings"]', $this->id()));
        self::assertSelectorNotExists('[role=progressbar]');
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function riskLevels(): iterable
    {
        // km driven after 182 days of a 15 000 km, one year lease, tolerance in percent.
        yield 'ok' => [5_000, 0, 'ok'];
        yield 'watch' => [7_000, 10, 'watch'];
        yield 'over' => [10_000, 0, 'over'];
    }

    #[DataProvider('riskLevels')]
    public function testEachRiskLevelIsShownWithAnIconAndATitleNotJustAColor(int $driven, int $tolerance, string $level): void
    {
        $this->lease('2026-01-01', 12, 15_000, $tolerance);
        $this->reading('2026-07-02 11:00:00', $driven);

        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        $alert = '[data-slot=alert][data-risk=' . $level . ']';
        self::assertSelectorExists($alert);
        self::assertSelectorExists($alert . ' svg[aria-hidden=true]');
        $color = ['ok' => 'green', 'watch' => 'amber', 'over' => 'red'][$level];
        self::assertSelectorExists($alert . '.bg-' . $color . '-50');
        self::assertSelectorTextContains($alert . ' [data-slot=alert-title]', ['ok' => 'OK', 'watch' => 'Watch', 'over' => 'Over the allowance'][$level]);
        self::assertSelectorTextContains($alert . ' [data-slot=alert-description]', 'Projected');
    }

    public function testTheFiguresMatchTheAssessment(): void
    {
        $this->lease('2026-01-01', 12, 15_000, 0);
        $this->reading('2026-07-02 11:00:00', 10_000);

        $assessment = self::getContainer()->get(LeaseTermsService::class)->assess($this->vehicle);
        self::assertInstanceOf(LeaseAssessment::class, $assessment);

        $this->client->request('GET', $this->url());

        $text = $this->text();
        $km = static fn (int $metres): string => self::kilometres($metres);
        self::assertStringContainsString('Driven ' . $km($assessment->projection->driven->metres()), $text);
        self::assertStringContainsString('Allowed to date ' . $km($assessment->projection->allowedToDate->metres()), $text);
        self::assertStringContainsString('Projected at lease end ' . $km($assessment->projection->projectedAtEnd->metres()), $text);
        self::assertStringContainsString($km($assessment->budget->perDay->metres()) . ' per day', $text);
        self::assertStringContainsString($km($assessment->budget->perWeek->metres()) . ' per week', $text);
        self::assertStringContainsString($km($assessment->budget->perMonth->metres()) . ' per month', $text);
        // The reading is dated 11:00 UTC on 2 July 2026.
        // The clock is at 12:00 UTC: the day is said in words, and the zone only in the hint.
        self::assertStringContainsString('Last reading Today at 11:00 AM Time zone: UTC', $text);
        // Projected excess and its cost, in the lease currency (0.12 CHF per km).
        $excess = $assessment->projectedExcess->metres() / 1000;
        self::assertGreaterThan(0, $excess);
        self::assertStringContainsString('Projected excess of ' . $km($assessment->projectedExcess->metres()), $text);
        $cost = new \NumberFormatter('en', \NumberFormatter::CURRENCY)->formatCurrency((float) $assessment->projectedExcessCost->toDecimal(), 'CHF');
        self::assertStringContainsString('about ' . self::normalize($cost), $text);
    }

    public function testTheProgressBarIsAccessibleAndMarksTheAllowanceEarned(): void
    {
        $this->lease('2026-01-01', 12, 15_000, 0);
        $this->reading('2026-07-02 11:00:00', 10_000);

        $crawler = $this->client->request('GET', $this->url());

        // 10 000 of 15 000 km is 67 %; 182 of 365 days are earned.
        $bar = $crawler->filter('[role=progressbar]');
        self::assertCount(1, $bar);
        self::assertSame('67', $bar->attr('aria-valuenow'));
        self::assertSame('0', $bar->attr('aria-valuemin'));
        self::assertSame('100', $bar->attr('aria-valuemax'));
        self::assertSame('Share of the allowance driven', $bar->attr('aria-label'));
        self::assertSame('67% of the allowance driven', $bar->attr('aria-valuetext'));
        self::assertSelectorTextContains('main', '67%');
        self::assertSelectorExists('main span.bg-red-600[style="left: 50%"][aria-hidden=true]');
    }

    public function testOnceTheAllowanceIsUsedUpTheBarStopsAt100AndThereIsNoBudget(): void
    {
        $this->lease('2026-01-01', 12, 15_000, 0);
        $this->reading('2026-07-02 11:00:00', 16_500);

        $crawler = $this->client->request('GET', $this->url());

        self::assertSame('100', $crawler->filter('[role=progressbar]')->attr('aria-valuenow'));
        self::assertSame('110% of the allowance driven', $crawler->filter('[role=progressbar]')->attr('aria-valuetext'));
        self::assertSelectorTextContains('main', '110%');
        self::assertSelectorTextContains('main', 'No budget left: the allowance is used up.');
        self::assertSelectorNotExists('main [data-slot=badge]');
    }

    public function testAnEndedLeaseShowsTheCostSoFarAndNoBudget(): void
    {
        $this->lease('2025-01-01', 12, 15_000, 0);
        // Read after the lease end, so that nothing is projected any more.
        $this->reading('2026-01-02 10:00:00', 16_000);

        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'The lease has ended');
        self::assertSelectorExists(\sprintf('main a[data-slot=button][href="/vehicles/%s/lease"]', $this->id()));
        self::assertSelectorTextContains('[data-slot=alert][data-risk=over]', 'The lease ended 1,000 km over the allowance, which costs');
        self::assertStringContainsString('CHF 120.00', $this->text());
        self::assertSelectorNotExists('main [data-slot=badge]');
        self::assertStringNotContainsString('What you can drive from now on', $this->text());
        self::assertStringNotContainsString('Projected at lease end', $this->text());
        self::assertSelectorExists('[role=progressbar]');
    }

    public function testTheDashboardIsTranslatedToFrench(): void
    {
        $this->lease('2026-01-01', 12, 15_000, 0);
        $this->reading('2026-07-02 11:00:00', 10_000);

        $this->client->request('GET', $this->url(), server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tableau de bord, Model 3');
        self::assertSelectorTextContains('[data-slot=alert-title]', 'Au-dessus du kilométrage');
        self::assertSelectorExists('[role=progressbar][aria-label="Part du kilométrage parcourue"]');
        $text = $this->text();
        self::assertStringContainsString('Parcouru 10', $text);
        self::assertStringContainsString('par jour', $text);
        self::assertStringContainsString('par semaine', $text);
        self::assertStringContainsString('par mois', $text);
        self::assertStringContainsString('Dernier relevé', $text);
        self::assertMatchesRegularExpression('/\d\s€|\d\sCHF/u', $text);
    }

    public function testTheEmptyStateIsTranslatedToFrench(): void
    {
        $this->client->request('GET', $this->url(), server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);

        self::assertSelectorTextContains('main', 'Ajoutez les conditions du leasing');
        self::assertSelectorTextContains('main a[data-slot=button]', 'Saisir les conditions du leasing');
    }

    private static function kilometres(int $metres): string
    {
        $formatter = new \NumberFormatter('en', \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 0);

        return $formatter->format($metres / 1000) . ' km';
    }

    private function lease(string $start, int $months, int $allowanceKm, int $tolerancePercent): void
    {
        $start = CarbonImmutable::parse($start, 'UTC');
        $this->entityManager->persist(new LeaseContract(
            $this->vehicle,
            LeaseTerm::fromCalendarDates($start, $start->addMonthsNoOverflow($months), EndDateConvention::Exclusive),
            Allowance::ofTotal(Distance::fromKilometres($allowanceKm)),
            Distance::fromKilometres(0),
            Money::fromDecimal('0.12', Currency::CHF),
            Tolerance::fromPercent($tolerancePercent),
        ));
        $this->entityManager->flush();
    }

    private function reading(string $at, int $km): void
    {
        $this->entityManager->persist(OdometerReading::manual($this->vehicle, CarbonImmutable::parse($at, 'UTC'), Distance::fromKilometres($km)));
        $this->entityManager->flush();
    }

    private function text(): string
    {
        return self::normalize($this->client->getCrawler()->filter('main')->text());
    }

    /** ICU separates a currency code from the amount with a no-break space. */
    private static function normalize(string $text): string
    {
        return preg_replace('/\s+/u', ' ', $text) ?? '';
    }

    private function id(): string
    {
        return $this->vehicle->getId()->toBase32();
    }

    private function url(): string
    {
        return \sprintf('/vehicles/%s', $this->id());
    }
}
