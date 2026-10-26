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
        self::assertSelectorTextContains('h1', 'Dashboard | Model 3');
        self::assertSelectorTextContains('main', 'Add the lease terms to see where you stand');
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
        self::assertSelectorTextContains('main', 'No reading since the lease start.');
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
        self::assertStringContainsString('minus 0 km at the lease start', $text);
        self::assertStringContainsString('Current pace', $text);
        self::assertStringContainsString('From now on', $text);
        self::assertStringContainsString('Normal pace', $text);
        self::assertStringContainsString('km / week', $text);
        self::assertStringContainsString('Allowed to date ' . $km($assessment->projection->allowedToDate->metres()), $text);
        self::assertStringContainsString('Projected at lease end ' . $km($assessment->projection->projectedAtEnd->metres()), $text);
        // The cards show the bare numbers, the unit is in the label under them.
        $number = static fn (int $metres): string => substr($km($metres), 0, -3);
        self::assertSame(
            [$number($assessment->budget->perDay->metres()), $number($assessment->budget->perWeek->metres()), $number($assessment->budget->perMonth->metres())],
            $this->figures('budget'),
        );
        self::assertSame(
            [$number($assessment->currentPace->perDay->metres()), $number($assessment->currentPace->perWeek->metres()), $number($assessment->currentPace->perMonth->metres())],
            $this->figures('current'),
        );
        // 15,000 km over 12 months: 1,250 km a lease month.
        self::assertSame('1,250', $this->figures('even')[2]);
        // The reading is dated 11:00 UTC on 2 July 2026.
        // The clock is at 12:00 UTC: the day is said in words, and the zone only in the hint.
        self::assertStringContainsString('Last reading Today at 11:00 AM Time zone: UTC', $text);
        // Projected excess and its cost, in the lease currency (0.12 CHF per km).
        $excess = $assessment->projectedExcess->metres() / 1000;
        self::assertGreaterThan(0, $excess);
        self::assertStringContainsString('Projected ' . $km($assessment->projectedExcess->metres()) . ' over at lease end', $text);
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
        self::assertSelectorTextContains('main', 'Allowance used up.');
        self::assertSelectorNotExists('[data-pace=budget] [data-figure]');
    }

    public function testTheRefreshButtonIsNotOnTheDashboardWithoutALease(): void
    {
        $this->client->request('GET', $this->url());

        self::assertSelectorNotExists('[data-live-name-value="Fetching:OdometerRefreshButton"]');
    }

    public function testTheRefreshButtonIsNotOnTheDashboardWithoutAReading(): void
    {
        $this->lease('2026-01-01', 12, 15_000, 0);

        $this->client->request('GET', $this->url());

        self::assertSelectorNotExists('[data-live-name-value="Fetching:OdometerRefreshButton"]');
    }

    public function testTheRefreshButtonIsNotOnTheDashboardOfAnEndedLease(): void
    {
        $this->lease('2025-01-01', 12, 15_000, 0);
        $this->reading('2026-01-02 10:00:00', 16_000);

        $this->client->request('GET', $this->url());

        self::assertSelectorNotExists('[data-live-name-value="Fetching:OdometerRefreshButton"]');
    }

    public function testTheRefreshButtonIsOnTheDashboardOfARunningLease(): void
    {
        $this->lease('2026-01-01', 12, 15_000, 0);
        $this->reading('2026-07-02 11:00:00', 10_000);

        $this->client->request('GET', $this->url());

        self::assertSelectorExists('main [data-live-name-value="Fetching:OdometerRefreshButton"] button[data-live-action-param=refresh]');
        self::assertSelectorTextContains('main [data-live-name-value="Fetching:OdometerRefreshButton"]', 'Refresh now');
        self::assertSelectorExists('body > [data-controller=toast-region][aria-live=polite]', 'The toast region is in the layout.');
    }

    public function testTheRefreshButtonIsTranslatedToFrench(): void
    {
        $this->lease('2026-01-01', 12, 15_000, 0);
        $this->reading('2026-07-02 11:00:00', 10_000);

        $this->client->request('GET', $this->url(), server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);

        self::assertSelectorTextContains('[data-live-name-value="Fetching:OdometerRefreshButton"]', 'Actualiser maintenant');
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
        self::assertSelectorTextContains('[data-slot=alert][data-risk=over]', 'Ended 1,000 km over,');
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
        self::assertSelectorTextContains('h1', 'Tableau de bord | Model 3');
        self::assertSelectorTextContains('[data-slot=alert-title]', 'Au-dessus du kilométrage');
        self::assertSelectorExists('[role=progressbar][aria-label="Part du kilométrage parcourue"]');
        $text = $this->text();
        self::assertStringContainsString('Parcouru 10', $text);
        self::assertStringContainsString('km / jour', $text);
        self::assertStringContainsString('km / semaine', $text);
        self::assertStringContainsString('km / mois', $text);
        self::assertStringContainsString('Dernier relevé', $text);
        self::assertMatchesRegularExpression('/\d\s€|\d\sCHF/u', $text);
    }

    public function testFrenchThousandsAreSeparatedByARegularNoBreakSpace(): void
    {
        $this->lease('2026-01-01', 12, 15_000, 0);
        $this->reading('2026-07-02 11:00:00', 10_000);

        $this->client->request('GET', $this->url(), server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);

        $figures = implode(' | ', $this->client->getCrawler()->filter('main dl dd.text-2xl')->each(static fn ($figure): string => $figure->text()));
        self::assertMatchesRegularExpression('/\\d\x{00A0}\\d{3}/u', $figures);
        self::assertStringNotContainsString("\u{202F}", $figures, 'The narrow space looks like no space at this size.');
    }

    public function testOnlyTheDigitsOfAFigureAreMonospaced(): void
    {
        $this->lease('2026-01-01', 12, 15_000, 0);
        $this->reading('2026-07-02 11:00:00', 10_000);

        $this->client->request('GET', $this->url());

        $mono = $this->client->getCrawler()->filter('main dl dd span.font-mono')->each(static fn ($figure): string => $figure->text());
        self::assertContains('10,000', $mono);
        foreach ($mono as $figure) {
            self::assertStringNotContainsString('km', $figure, 'The unit stays in the regular font.');
            self::assertStringNotContainsString('Today', $figure, 'A date is not a number.');
        }
        self::assertStringContainsString("10,000\u{00A0}km", implode('|', $this->client->getCrawler()->filter('main dd.text-2xl')->each(static fn ($figure): string => $figure->text())));
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

    /**
     * The figures (per day, week and month) of a row of the pace table.
     *
     * @return list<string>
     */
    private function figures(string $row): array
    {
        return $this->client->getCrawler()->filter(\sprintf('[data-pace=%s] [data-figure]', $row))->each(static fn ($cell): string => preg_replace('/\s+/u', ' ', trim($cell->text())) ?? '');
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
