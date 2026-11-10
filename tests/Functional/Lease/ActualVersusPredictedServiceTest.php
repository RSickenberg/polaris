<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Lease;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Lease\Domain\Comparison;
use Polaris\Lease\Domain\LeaseAssessment;
use Polaris\Lease\Domain\Pace;
use Polaris\Lease\Domain\RiskLevel;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Lease\Entity\ProjectionSnapshot;
use Polaris\Lease\Form\Model\LeaseContractData;
use Polaris\Lease\Service\ActualVersusPredictedService;
use Polaris\Lease\Service\LeaseReturnService;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Lease\Service\ProjectionSnapshotService;
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
        self::assertSame([50_000, 45_000, 5_000], $this->figures($today));
        self::assertFalse($result->budget[0]->live, 'A stored snapshot is not live.');
        $pace = $result->pace[0]->comparison;
        self::assertSame([50_000, 40_000, 10_000], $this->figures($pace));
        $week = $result->budget[2]->comparison;
        self::assertSame([750_000, 750_000, 0], $this->figures($week));
        // The projection now is 36,500 km; this morning's said 36,000 km.
        self::assertSame(500_000, $result->projectedEnd[0]->comparison?->delta->metres());
        self::assertNull($result->budget[3]->comparison, 'No snapshot 14 days before.');
        self::assertNull($result->budget[4]->comparison, 'No snapshot 30 days before.');
    }

    public function testAfterATermsEditTheOldSnapshotsAreLeftOutUntilTheyAreRecalculatedUnderTheNewTerms(): void
    {
        $this->lease();
        $this->reading('2026-07-02 12:00:00', 18_250);
        $this->reading('2026-07-10 12:00:00', 19_050);
        // A row taken under the original terms: 90 km a day, projecting 36,000 km.
        $this->snapshot('2026-07-03', projectedAtEndKm: 36_000, dailyBudgetKm: 90, paceMetresPerDay: 100_000.0);
        self::assertNotNull($this->comparison->compare($this->vehicle)?->budget[2]->comparison, 'Before the edit.');

        // Half the allowance: the budget and the projection of every past day change.
        $this->lease(allowance: 18_250);

        $stale = $this->comparison->compare($this->vehicle);
        self::assertNotNull($stale);
        self::assertTrue($stale->recalculating);
        self::assertNull($stale->budget[2]->comparison, 'The row of 7 days ago is on other terms, so it is not shown.');
        self::assertNull($stale->projectedEnd[2]->comparison);
        self::assertNotNull($stale->budget[0]->comparison, 'Today is rebuilt under the current terms.');
        self::assertTrue($stale->budget[0]->live);

        self::getContainer()->get(ProjectionSnapshotService::class)->recalculate($this->vehicle);

        $fresh = $this->comparison->compare($this->vehicle);
        self::assertNotNull($fresh);
        self::assertFalse($fresh->recalculating);
        $week = $fresh->budget[2]->comparison;
        self::assertNotNull($week);
        // The row of 3 July now holds the budget of the current terms, over the 7.5 days to the latest reading.
        $budget = $this->leases->assessAt($this->vehicle, CarbonImmutable::parse('2026-07-03', 'UTC'));
        self::assertInstanceOf(LeaseAssessment::class, $budget);
        self::assertNotSame(90_000, $budget->budget->perDay->metres());
        self::assertEqualsWithDelta($budget->budget->perDay->metres() * 7.5, $week->expected->metres(), 1);
    }

    public function testTodayIsTheDayOfTheClockAndWaitsForTheFirstReadingOfThatDay(): void
    {
        $this->lease();
        $this->reading('2026-07-09 12:00:00', 18_950);
        $this->snapshot('2026-07-10', projectedAtEndKm: 36_000, dailyBudgetKm: 90, paceMetresPerDay: 80_000.0);
        $this->snapshot('2026-07-03', projectedAtEndKm: 36_500, dailyBudgetKm: 100, paceMetresPerDay: 100_000.0);
        // 02:00 on the 10th: the reading of the evening before is not today's.
        self::mockTime('2026-07-10 02:00:00 UTC');

        $result = $this->comparison->compare($this->vehicle);

        self::assertNotNull($result);
        self::assertTrue($result->budget[0]->awaitingReading);
        self::assertNull($result->budget[0]->comparison);
        // The week starts at the owner's midnight of the 3rd and ends at the reading, 6.5 days later.
        self::assertSame(650_000, $result->budget[2]->comparison?->actual->metres());
    }

    public function testTodayIsRebuiltWhenTheMidnightJobSavedNothingForIt(): void
    {
        $this->lease();
        $this->reading('2026-07-09 12:00:00', 19_000);
        $this->reading('2026-07-10 12:00:00', 19_050);
        // An older snapshot exists, but today must not fall back on it: the job's own figures are rebuilt.
        $this->snapshot('2026-07-08', projectedAtEndKm: 30_000, dailyBudgetKm: 10, paceMetresPerDay: 10_000.0);

        $result = $this->comparison->compare($this->vehicle);

        self::assertNotNull($result);
        self::assertSame(0, $result->budget[0]->fallbackDays);
        self::assertTrue($result->budget[0]->live, 'Rebuilt on the fly, so shown as live.');
        // The odometer is linear between the readings: 19,025 km at midnight, so 25 km driven today.
        self::assertSame(25_000, $result->budget[0]->comparison?->actual->metres());
        self::assertNotNull($result->pace[0]->comparison);
        self::assertNotNull($result->projectedEnd[0]->comparison);
        self::assertNull($result->budget[2]->comparison, 'The other windows still need a stored snapshot.');
        $this->assertNoSnapshotStoredFor('2026-07-10');
    }

    private function assertNoSnapshotStoredFor(string $localDay): void
    {
        $contract = $this->leases->find($this->vehicle);
        self::assertInstanceOf(LeaseContract::class, $contract);
        $days = array_map(
            static fn (ProjectionSnapshot $snapshot): string => $snapshot->getLocalDay()->format('Y-m-d'),
            $this->entityManager->getRepository(ProjectionSnapshot::class)->findBy(['leaseContract' => $contract]),
        );
        self::assertNotContains($localDay, $days);
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

        self::getContainer()->get(LeaseReturnService::class)->markReturned(
            $this->vehicle,
            new \DateTimeImmutable('2026-07-10'),
        );
        self::assertNull($this->comparison->compare($this->vehicle), 'Returned.');
    }

    public function testAnEndedLeaseIsNotCompared(): void
    {
        $this->lease();
        $this->reading('2026-07-10 12:00:00', 19_050);
        self::mockTime('2027-02-01 12:00:00 UTC');

        self::assertNull($this->comparison->compare($this->vehicle));
    }

    /**
     * @return array{int|null, int|null, int|null} actual, expected and delta in metres
     */
    private function figures(?Comparison $comparison): array
    {
        return [$comparison?->actual->metres(), $comparison?->expected->metres(), $comparison?->delta->metres()];
    }

    private function lease(int $allowance = 36_500): void
    {
        $data = new LeaseContractData();
        $data->startDate = new \DateTimeImmutable('2026-01-01');
        $data->endDate = new \DateTimeImmutable('2027-01-01');
        $data->allowance = $allowance;
        $data->startOdometer = 0;
        $data->excessCostPerKm = '0.12';
        $data->currency = Currency::CHF;
        $data->tolerancePercent = 0;
        $this->leases->save($this->vehicle, $data);
    }

    private function reading(string $at, int $km): void
    {
        $this->entityManager->persist(
            OdometerReading::manual($this->vehicle, CarbonImmutable::parse($at, 'UTC'), Distance::fromKilometres($km)),
        );
        $this->entityManager->flush();
    }

    private function snapshot(
        string $localDay,
        int $projectedAtEndKm,
        int $dailyBudgetKm,
        ?float $paceMetresPerDay,
    ): void {
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
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE projection_snapshot SET cutoff_at = NULL, overall_pace_metres_per_day = NULL, '
                . 'recent_pace_metres_per_day = NULL, blended_pace_metres_per_day = NULL',
            );
            $this->entityManager->clear();
            $vehicle = $this->entityManager->find(Vehicle::class, $this->vehicle->getId());
            self::assertInstanceOf(Vehicle::class, $vehicle);
            $this->vehicle = $vehicle;
        }
    }
}
