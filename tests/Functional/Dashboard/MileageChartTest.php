<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Dashboard;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Dashboard\Service\DashboardService;
use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\Tolerance;
use Polaris\Lease\Entity\LeaseContract;
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

#[CoversClass(DashboardService::class)]
final class MileageChartTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use SignsIn;

    private const string CHART = '[data-controller=chart][role=img]';

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

    public function testAnActiveLeaseShowsTheChartWithItsThreeCurves(): void
    {
        $this->lease('2026-01-01', 15_000);
        $this->reading('2026-04-01 10:00:00', 4_000);
        $this->reading('2026-07-02 11:00:00', 10_000);

        $crawler = $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        $chart = $crawler->filter(self::CHART);
        self::assertCount(1, $chart);
        self::assertSame('Line chart of the distance driven since the lease start, against the linear allowance and the projection to the lease end', $chart->attr('aria-label'));
        self::assertSame('line', $chart->attr('data-chart-type-value'));
        self::assertSame('en', $chart->attr('data-chart-locale-value'));
        self::assertSame('UTC', $chart->attr('data-chart-time-zone-value'));

        $series = $this->series($chart->attr('data-chart-series-value'));
        self::assertSame(['Driven', 'Allowed', 'Projection'], array_column($series, 'name'));
        // Points are [Unix milliseconds, kilometres]: the start point, then one per reading.
        self::assertSame(
            [[self::ms('2026-01-01'), 0], [self::ms('2026-04-01 10:00:00'), 4_000], [self::ms('2026-07-02 11:00:00'), 10_000]],
            $series[0]['data'],
        );
        self::assertSame([[self::ms('2026-01-01'), 0], [self::ms('2027-01-01'), 15_000]], $series[1]['data']);
        self::assertSame([self::ms('2026-07-02 11:00:00'), 10_000], $series[2]['data'][0]);
        self::assertSame(self::ms('2027-01-01'), $series[2]['data'][1][0]);
        self::assertGreaterThan(10_000, $series[2]['data'][1][1]);
        self::assertSelectorTextContains('main', 'Mileage over the lease');
    }

    public function testAnEndedLeaseShowsTheChartWithoutProjection(): void
    {
        $this->lease('2025-01-01', 15_000);
        $this->reading('2026-01-02 10:00:00', 16_000);

        $crawler = $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        $series = $this->series($crawler->filter(self::CHART)->attr('data-chart-series-value'));
        self::assertSame(['Driven', 'Allowed'], array_column($series, 'name'));
        // The reading past the lease end is kept.
        self::assertSame([self::ms('2026-01-02 10:00:00'), 16_000], $series[0]['data'][1]);
    }

    public function testWithoutALeaseThereIsNoChart(): void
    {
        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(self::CHART);
    }

    public function testWithoutAReadingAfterTheLeaseStartThereIsNoChart(): void
    {
        $this->lease('2026-01-01', 15_000);
        $this->reading('2025-12-01 10:00:00', 40);

        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(self::CHART);
    }

    public function testTheChartIsTranslatedAndGetsTheFrenchLocale(): void
    {
        $this->lease('2026-01-01', 15_000);
        $this->reading('2026-07-02 11:00:00', 10_000);

        $crawler = $this->client->request('GET', $this->url(), server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);

        self::assertResponseIsSuccessful();
        $chart = $crawler->filter(self::CHART);
        self::assertSame('fr', $chart->attr('data-chart-locale-value'));
        self::assertSame(['Parcouru', 'Autorisé', 'Projection'], array_column($this->series($chart->attr('data-chart-series-value')), 'name'));
        self::assertStringContainsString('Distance (km)', (string) $chart->attr('data-chart-options-value'));
        self::assertSelectorTextContains('main', 'Kilométrage sur la durée du bail');
    }

    /**
     * @return list<array{name: string, data: list<array{int, int|float}>}>
     */
    private function series(?string $json): array
    {
        self::assertNotNull($json);

        /** @var list<array{name: string, data: list<array{int, int|float}>}> $series */
        $series = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return $series;
    }

    private static function ms(string $at): int
    {
        return CarbonImmutable::parse($at, 'UTC')->getTimestamp() * 1000;
    }

    private function lease(string $start, int $allowanceKm): void
    {
        $start = CarbonImmutable::parse($start, 'UTC');
        $this->entityManager->persist(new LeaseContract(
            $this->vehicle,
            LeaseTerm::fromCalendarDates($start, $start->addMonthsNoOverflow(12), EndDateConvention::Exclusive),
            Allowance::ofTotal(Distance::fromKilometres($allowanceKm)),
            Distance::fromKilometres(0),
            Money::fromDecimal('0.12', Currency::CHF),
            Tolerance::fromPercent(0),
        ));
        $this->entityManager->flush();
    }

    private function reading(string $at, int $km): void
    {
        $this->entityManager->persist(OdometerReading::manual($this->vehicle, CarbonImmutable::parse($at, 'UTC'), Distance::fromKilometres($km)));
        $this->entityManager->flush();
    }

    private function url(): string
    {
        return \sprintf('/vehicles/%s', $this->vehicle->getId()->toBase32());
    }
}
