<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Lease;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Lease\Domain\Pace;
use Polaris\Lease\Domain\RiskLevel;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Lease\Entity\ProjectionSnapshot;
use Polaris\Lease\Form\Model\LeaseContractData;
use Polaris\Lease\Service\ActualVersusPredictedService;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * A lease of 36,500 km a year from 2026-01-01, started at 0 km; the car drives 100 km a day, evenly. The reading of
 * 2026-07-10 12:00 UTC is 190.5 days in: 19,050 km, and 36,500 km projected at the end.
 */
#[CoversClass(ActualVersusPredictedService::class)]
final class ActualVersusPredictedServiceTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SignsIn;

    private EntityManagerInterface $entityManager;
    private LeaseTermsService $leases;
    private ActualVersusPredictedService $comparison;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-07-10 13:00:00 UTC');
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->leases = $container->get(LeaseTermsService::class);
        $this->comparison = $container->get(ActualVersusPredictedService::class);
        $this->vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000301'), 'Model 3', self::createUser());
        $this->entityManager->persist($this->vehicle);
        $this->entityManager->flush();
    }

    public function testTheLatestFiguresAreComparedWithTheSnapshotsOfTheLastDays(): void
    {
        $this->lease();
        $this->reading('2026-07-10 12:00:00', 19_050);
        // Today started at 00:00, 12 hours before the reading: 50 km driven.
        $this->snapshot('2026-07-10', projectedAtEndKm: 36_000, dailyBudgetKm: 90, paceMetresPerDay: 80_000.0);
        // 7 days before: 7.5 days, 750 km driven; the budget expects exactly that.
        $this->snapshot('2026-07-03', projectedAtEndKm: 36_500, dailyBudgetKm: 100, paceMetresPerDay: 100_000.0);

        $result = $this->comparison->compare($this->vehicle);

        self::assertNotNull($result);
        $today = $result->budget[0]->comparison;
        self::assertSame([50_000, 45_000, 5_000], [$today?->actual->metres(), $today?->expected->metres(), $today?->delta->metres()]);
        $pace = $result->pace[0]->comparison;
        self::assertSame([50_000, 40_000, 10_000], [$pace?->actual->metres(), $pace?->expected->metres(), $pace?->delta->metres()]);
        $week = $result->budget[1]->comparison;
        self::assertSame([750_000, 750_000, 0], [$week?->actual->metres(), $week?->expected->metres(), $week?->delta->metres()]);
        // The projection now is 36,500 km; this morning's said 36,000 km.
        self::assertSame(500_000, $result->projectedEnd[0]->comparison?->delta->metres());
        self::assertNull($result->budget[2]->comparison, 'No snapshot 14 days before.');
        self::assertNull($result->budget[3]->comparison, 'No snapshot 30 days before.');
    }

    public function testTodayIsTheDayOfTheLatestReadingEvenWhenItIsOld(): void
    {
        $this->lease();
        $this->reading('2026-07-10 12:00:00', 19_050);
        $this->snapshot('2026-07-10', projectedAtEndKm: 36_000, dailyBudgetKm: 90, paceMetresPerDay: 80_000.0);
        // A week without a reading: the clock moved on, the figures did not.
        self::mockTime('2026-07-17 09:00:00 UTC');

        $result = $this->comparison->compare($this->vehicle);

        self::assertSame(45_000, $result?->budget[0]->comparison?->expected->metres());
    }

    public function testASnapshotSavedBeforeThePaceWasStoredHasNoPaceRow(): void
    {
        $this->lease();
        $this->reading('2026-07-10 12:00:00', 19_050);
        $this->snapshot('2026-07-10', projectedAtEndKm: 36_000, dailyBudgetKm: 90, paceMetresPerDay: null);

        $result = $this->comparison->compare($this->vehicle);

        self::assertNotNull($result?->budget[0]->comparison);
        self::assertNull($result->pace[0]->comparison);
    }

    public function testNothingIsComparedWithoutALeaseOrAReadingOrOnceTheLeaseIsOver(): void
    {
        self::assertNull($this->comparison->compare($this->vehicle), 'No lease.');

        $this->lease();
        self::assertNull($this->comparison->compare($this->vehicle), 'No reading.');

        $this->reading('2026-07-10 12:00:00', 19_050);
        self::assertNotNull($this->comparison->compare($this->vehicle));

        $this->leases->markReturned($this->vehicle, new \DateTimeImmutable('2026-07-10'));
        self::assertNull($this->comparison->compare($this->vehicle), 'Returned.');
    }

    public function testAnEndedLeaseIsNotCompared(): void
    {
        $this->lease();
        $this->reading('2026-07-10 12:00:00', 19_050);
        self::mockTime('2027-02-01 12:00:00 UTC');

        self::assertNull($this->comparison->compare($this->vehicle));
    }

    private function lease(): void
    {
        $data = new LeaseContractData();
        $data->startDate = new \DateTimeImmutable('2026-01-01');
        $data->endDate = new \DateTimeImmutable('2027-01-01');
        $data->allowance = 36_500;
        $data->startOdometer = 0;
        $data->excessCostPerKm = '0.12';
        $data->currency = Currency::CHF;
        $data->tolerancePercent = 0;
        $this->leases->save($this->vehicle, $data);
    }

    private function reading(string $at, int $km): void
    {
        $this->entityManager->persist(OdometerReading::manual($this->vehicle, CarbonImmutable::parse($at, 'UTC'), Distance::fromKilometres($km)));
        $this->entityManager->flush();
    }

    private function snapshot(string $localDay, int $projectedAtEndKm, int $dailyBudgetKm, ?float $paceMetresPerDay): void
    {
        $contract = $this->leases->find($this->vehicle);
        self::assertInstanceOf(LeaseContract::class, $contract);
        $midnight = CarbonImmutable::parse($localDay, 'UTC');
        $pace = null === $paceMetresPerDay ? null : Pace::fromMetresPerDay($paceMetresPerDay);
        $snapshot = new ProjectionSnapshot(
            $contract,
            $midnight,
            $midnight,
            $midnight->addMinutes(5),
            $midnight->subHours(1),
            Distance::fromKilometres(1),
            Distance::fromKilometres(1),
            Distance::fromKilometres($projectedAtEndKm),
            Distance::fromKilometres($dailyBudgetKm),
            RiskLevel::Ok,
            $pace ?? Pace::fromMetresPerDay(1.0),
            null,
            $pace ?? Pace::fromMetresPerDay(1.0),
        );
        $this->entityManager->persist($snapshot);
        $this->entityManager->flush();

        if (null === $paceMetresPerDay) {
            // A row saved before the pace and the cutoff were stored has neither.
            $this->entityManager->getConnection()->executeStatement('UPDATE projection_snapshot SET cutoff_at = NULL, overall_pace_metres_per_day = NULL, recent_pace_metres_per_day = NULL, blended_pace_metres_per_day = NULL');
            $this->entityManager->clear();
            $vehicle = $this->entityManager->find(Vehicle::class, $this->vehicle->getId());
            self::assertInstanceOf(Vehicle::class, $vehicle);
            $this->vehicle = $vehicle;
        }
    }
}
