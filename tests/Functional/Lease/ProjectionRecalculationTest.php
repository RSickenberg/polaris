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
use Polaris\Lease\EventListener\QueueProjectionRecalculation;
use Polaris\Lease\Form\Model\LeaseContractData;
use Polaris\Lease\Message\RecalculateProjectionSnapshots;
use Polaris\Lease\MessageHandler\RecalculateProjectionSnapshotsHandler;
use Polaris\Lease\Service\Enum\SnapshotOutcome;
use Polaris\Lease\Service\LeaseReturnService;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Lease\Service\ProjectionSnapshotService;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\OwnerMidnight;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A lease of 15,000 km for the year 2026 from 0 km, and one reading of 10,000 km on 2026-07-02 12:00 UTC (182.5 days
 * in, so 20,000 km projected at the end under the original terms). Snapshots are taken at the midnights of 3, 4 and 5
 * July.
 */
#[CoversClass(ProjectionSnapshotService::class)]
#[CoversClass(QueueProjectionRecalculation::class)]
#[CoversClass(RecalculateProjectionSnapshotsHandler::class)]
#[CoversClass(LeaseContract::class)]
final class ProjectionRecalculationTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SignsIn;

    private EntityManagerInterface $entityManager;
    private LeaseTermsService $leases;
    private ProjectionSnapshotService $snapshots;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-07-03 00:05:00 UTC');
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->leases = $container->get(LeaseTermsService::class);
        $this->snapshots = $container->get(ProjectionSnapshotService::class);

        $this->vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000091'), 'Model 3', self::createUser());
        $this->entityManager->persist($this->vehicle);
        $this->entityManager->persist(
            OdometerReading::manual(
                $this->vehicle,
                CarbonImmutable::parse('2026-07-02 12:00:00', 'UTC'),
                Distance::fromKilometres(10_000),
            )
        );
        $this->entityManager->flush();
        $this->leases->save($this->vehicle, self::data());

        foreach (['2026-07-03', '2026-07-04', '2026-07-05'] as $day) {
            self::mockTime($day . ' 00:05:00 UTC');
            self::assertSame(SnapshotOutcome::Saved, $this->snapshots->save($this->vehicle, $this->midnight($day)));
        }

        $this->asyncTransport()->reset();
        self::mockTime('2026-07-05 08:00:00 UTC');
    }

    public function testASaveThatChangesNothingTheProjectionDependsOnQueuesNothingAndTouchesNoRow(): void
    {
        $before = $this->figures();

        // The excess cost and the tolerance change the money and the risk level, not the distances.
        $this->leases->save($this->vehicle, self::data(excessCost: '0.30', tolerance: 5));
        $this->leases->save($this->vehicle, self::data());

        self::assertSame([], $this->recalculations());
        self::assertFalse($this->contract()->hasStaleSnapshots());
        self::assertSame($before, $this->figures());
    }

    public function testANewContractQueuesNothing(): void
    {
        $other = new Vehicle(new Vin('5YJ3E7EB2NF000092'), 'Model Y', self::createUser());
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        $this->leases->save($other, self::data());

        self::assertSame([], $this->recalculations());
    }

    public function testANewEndDateRecalculatesEveryRowAsAsyncWork(): void
    {
        $this->leases->save($this->vehicle, self::data(end: '2028-01-01'));

        // Queued, not run: the request that saved the terms did not wait, and until it ran the rows are stale.
        $queued = $this->recalculations();
        self::assertCount(1, $queued);
        self::assertSame($this->vehicle->getId()->toRfc4122(), $queued[0]->vehicleId);
        self::assertTrue($this->contract()->hasStaleSnapshots());
        self::assertSame(20_000_000, $this->figures()['2026-07-03']['projectedAtEnd']);

        $result = $this->snapshots->recalculate($this->vehicle);

        // Two years: 15,000 km a year is 30,000 km, and 10,000 km after 182.5 of 730 days projects 40,000 km.
        self::assertSame([3, 0, 0], [$result->recomputed, $result->deleted, $result->unchanged]);
        self::assertFalse($result->busy);
        // The newest row, the 5th, goes from the projection of one year to the one of two.
        self::assertSame('2026-07-05', $result->latest?->localDay);
        self::assertSame(20_000_000, $result->latest->projectedAtEndBefore->metres());
        self::assertSame(40_000_000, $result->latest->projectedAtEndAfter->metres());
        self::assertSame(RiskLevel::Over, $result->latest->riskLevelBefore);
        self::assertSame(40_000_000, $this->figures()['2026-07-03']['projectedAtEnd']);
        self::assertFalse($this->contract()->hasStaleSnapshots());
        self::assertSame('2026-07-05T08:00:00+00:00', $this->rows()[0]->getComputedAt()->toIso8601String());
    }

    public function testANewAllowanceRecalculatesTheBudgetAndTheRiskLevel(): void
    {
        self::assertSame(RiskLevel::Over, $this->rows()[0]->getRiskLevel());
        $budgetBefore = $this->figures()['2026-07-03']['dailyBudget'];

        // 40,000 km over the year: 10,000 km driven in half the time is well under it.
        $this->leases->save($this->vehicle, self::data(allowance: 40_000));
        $result = $this->snapshots->recalculate($this->vehicle);

        self::assertSame(3, $result->recomputed);
        $row = $this->rows()[0];
        self::assertSame(RiskLevel::Ok, $row->getRiskLevel());
        self::assertGreaterThan($budgetBefore, $row->getDailyBudget()->metres());
        self::assertSame(20_000_000, $row->getProjectedAtEnd()->metres(), 'The projection is the same: the pace is.');
    }

    public function testANewStartOdometerRecalculatesTheDistanceDriven(): void
    {
        $this->leases->save($this->vehicle, self::data(startOdometer: 2_000));
        $this->snapshots->recalculate($this->vehicle);

        // 10,000 km on the odometer, 2,000 km of which were there when the lease began.
        self::assertSame(8_000_000, $this->rows()[0]->getDriven()->metres());
    }

    public function testANewStartDateDeletesTheRowsBeforeItAndKeepsTheOthers(): void
    {
        // Rows of the days before the reading cannot be made by the job (no data); they stand for ones that predate
        // the new start.
        $this->insertRow('2026-06-15');
        $this->insertRow('2026-06-30');

        // Six months from 1 July: the midnights of 15 and 30 June now come before the lease.
        $this->leases->save($this->vehicle, self::data(start: '2026-07-01'));
        $result = $this->snapshots->recalculate($this->vehicle);

        self::assertSame([3, 2, 0], [$result->recomputed, $result->deleted, $result->unchanged]);
        self::assertSame(['2026-07-03', '2026-07-04', '2026-07-05'], array_keys($this->figures()));
    }

    public function testARowSavedBeforeTheOwnersMidnightIsDeleted(): void
    {
        $this->insertRow('2026-07-01');
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE projection_snapshot SET cutoff_at = NULL, overall_pace_metres_per_day = NULL, '
            . "blended_pace_metres_per_day = NULL WHERE local_day = '2026-07-01'",
        );

        $this->leases->save($this->vehicle, self::data(allowance: 20_000));
        $result = $this->snapshots->recalculate($this->vehicle);

        self::assertSame([3, 1, 0], [$result->recomputed, $result->deleted, $result->unchanged]);
        self::assertNotContains('2026-07-01', array_keys($this->figures()));
    }

    public function testARowWhoseAssessmentCannotBeMadeIsDeleted(): void
    {
        // A row of 3 July whose midnight is before the only reading once that reading is moved later: no data.
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE odometer_reading SET read_at = '2026-07-04 12:00:00'",
        );
        $this->entityManager->clear();
        $this->vehicle = $this->find();

        $this->leases->save($this->vehicle, self::data(allowance: 20_000));
        $result = $this->snapshots->recalculate($this->vehicle);

        self::assertSame([1, 2, 0], [$result->recomputed, $result->deleted, $result->unchanged]);
        self::assertSame(['2026-07-05'], array_keys($this->figures()));
    }

    public function testAMidnightPastTheNewEndBecomesAnEndedRow(): void
    {
        self::assertGreaterThan(0, $this->figures()['2026-07-03']['dailyBudget']);

        // From 3 January to 3 July (exclusive): the lease is over at the midnight of the 3rd, the first of the rows.
        $this->leases->save($this->vehicle, self::data(start: '2026-01-03', end: '2026-07-03'));
        $result = $this->snapshots->recalculate($this->vehicle);

        self::assertSame([3, 0, 0], [$result->recomputed, $result->deleted, $result->unchanged]);
        // No time left, so no budget.
        self::assertSame([0, 0, 0], array_column($this->figures(), 'dailyBudget'));
    }

    public function testRowsFromAfterTheReturnAreDeletedAndTheOthersRecalculated(): void
    {
        // Returned on 4 July: back to the bank from the midnight of the 5th, so that row is final, not a snapshot.
        self::getContainer()->get(LeaseReturnService::class)->markReturned(
            $this->find(),
            new \DateTimeImmutable('2026-07-04'),
        );
        $this->vehicle = $this->find();

        $this->leases->save($this->vehicle, self::data(allowance: 20_000));
        $result = $this->snapshots->recalculate($this->vehicle);

        self::assertSame([2, 1, 0], [$result->recomputed, $result->deleted, $result->unchanged]);
        self::assertSame(['2026-07-03', '2026-07-04'], array_keys($this->figures()));
    }

    public function testRunningItAgainChangesNothing(): void
    {
        $this->leases->save($this->vehicle, self::data(end: '2028-01-01'));
        $this->snapshots->recalculate($this->vehicle);
        $figures = $this->figures();

        $second = $this->snapshots->recalculate($this->vehicle);

        self::assertSame([0, 0, 3], [$second->recomputed, $second->deleted, $second->unchanged]);
        self::assertSame($figures, $this->figures());
    }

    public function testWhileTheRecalculationRunsTheMidnightJobWaitsAndTheOtherWayRound(): void
    {
        $this->leases->save($this->vehicle, self::data(end: '2028-01-01'));
        $contract = $this->contract();
        $lock = self::getContainer()->get(LockFactory::class)->createLock(
            'lease_snapshot_' . $contract->getId()->toBase32(),
            60,
        );
        self::assertTrue($lock->acquire());

        try {
            // A midnight job holds the lock: the recalculation does nothing and asks to be tried again.
            self::assertTrue($this->snapshots->recalculate($this->vehicle)->busy);
            self::assertTrue($this->contract()->hasStaleSnapshots());
            self::assertSame(20_000_000, $this->figures()['2026-07-03']['projectedAtEnd']);

            $handler = self::getContainer()->get(RecalculateProjectionSnapshotsHandler::class);

            try {
                $handler(new RecalculateProjectionSnapshots($this->vehicle->getId()->toRfc4122()));
                self::fail('The handler should ask for a retry.');
            } catch (RecoverableMessageHandlingException $e) {
                self::assertSame(30_000, $e->getRetryDelay());
            }
        } finally {
            $lock->release();
        }

        // The job of the next midnight finds the new terms, whatever the order.
        self::mockTime('2026-07-06 00:05:00 UTC');
        self::assertSame(SnapshotOutcome::Saved, $this->snapshots->save($this->vehicle, $this->midnight('2026-07-06')));
        self::assertSame(40_000_000, $this->figures()['2026-07-06']['projectedAtEnd']);

        $result = $this->snapshots->recalculate($this->vehicle);

        self::assertSame([3, 0, 1], [$result->recomputed, $result->deleted, $result->unchanged]);
        self::assertSame(
            [40_000_000],
            array_values(array_unique(array_column($this->figures(), 'projectedAtEnd'))),
            'No row is left under the old terms.',
        );
    }

    public function testAnEditMadeWhileItRunsStaysStaleUntilItsOwnRecalculation(): void
    {
        $this->leases->save($this->vehicle, self::data(end: '2028-01-01'));
        $revision = $this->contract()->getTermsRevision();
        // A second edit lands before the first recalculation is marked done (a higher revision than it read).
        $this->leases->save($this->vehicle, self::data(end: '2029-01-01'));
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE lease_contract SET snapshots_revision = :revision WHERE snapshots_revision < :revision',
            ['revision' => $revision],
        );
        $this->entityManager->clear();

        self::assertTrue($this->contract()->hasStaleSnapshots());
        self::assertCount(2, $this->recalculations());

        $this->snapshots->recalculate($this->find());

        self::assertFalse($this->contract()->hasStaleSnapshots());
    }

    public function testTheHandlerRecalculatesAndIgnoresAnUnknownVehicle(): void
    {
        $this->leases->save($this->vehicle, self::data(end: '2028-01-01'));
        $handler = self::getContainer()->get(RecalculateProjectionSnapshotsHandler::class);

        $handler(new RecalculateProjectionSnapshots('01JZZZZZZZZZZZZZZZZZZZZZZZ'));
        self::assertSame(20_000_000, $this->figures()['2026-07-03']['projectedAtEnd']);

        $handler(new RecalculateProjectionSnapshots($this->vehicle->getId()->toRfc4122()));
        self::assertSame(40_000_000, $this->figures()['2026-07-03']['projectedAtEnd']);
    }

    public function testTheHandlerLogsWhatTheEditDidToTheNewestPrediction(): void
    {
        $this->leases->save($this->vehicle, self::data(end: '2028-01-01'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            'Projection snapshots recalculated.',
            [
                'vehicle' => $this->vehicle->getId()->toRfc4122(),
                'recomputed' => 3,
                'deleted' => 0,
                'unchanged' => 0,
                'latest_day' => '2026-07-05',
                'projected_end_before' => 20_000_000,
                'projected_end_after' => 40_000_000,
                // 5,000 km left over 182.5 days, then 20,000 km over 547.5.
                'daily_budget_before' => 27_397,
                'daily_budget_after' => 36_530,
                'risk_before' => 'over',
                'risk_after' => 'over',
            ],
        );
        $handler = new RecalculateProjectionSnapshotsHandler($this->snapshots, $this->entityManager, $logger);

        $handler(new RecalculateProjectionSnapshots($this->vehicle->getId()->toRfc4122()));
    }

    public function testAVehicleWithoutAContractHasNothingToRecalculate(): void
    {
        $other = new Vehicle(new Vin('5YJ3E7EB2NF000093'), 'Model Y', self::createUser());
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        $result = $this->snapshots->recalculate($other);

        self::assertSame([0, 0, 0], [$result->recomputed, $result->deleted, $result->unchanged]);
    }

    private function midnight(string $day): OwnerMidnight
    {
        return OwnerMidnight::opening(CarbonImmutable::parse($day, 'UTC'), 'UTC');
    }

    private function find(): Vehicle
    {
        $this->entityManager->clear();
        $vehicle = $this->entityManager->find(Vehicle::class, $this->vehicle->getId());
        self::assertInstanceOf(Vehicle::class, $vehicle);

        return $vehicle;
    }

    private function contract(): LeaseContract
    {
        $this->entityManager->clear();
        $contract = $this->entityManager->getRepository(LeaseContract::class)->findOneBy([]);
        self::assertInstanceOf(LeaseContract::class, $contract);

        return $contract;
    }

    /**
     * @return list<ProjectionSnapshot>
     */
    private function rows(): array
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(ProjectionSnapshot::class)->findBy([], ['localDay' => 'ASC']);
    }

    /**
     * @return array<string, array{projectedAtEnd: int, allowedToDate: int, dailyBudget: int, driven: int}> by local day
     */
    private function figures(): array
    {
        $figures = [];

        foreach ($this->rows() as $row) {
            $figures[$row->getLocalDay()->format('Y-m-d')] = [
                'projectedAtEnd' => $row->getProjectedAtEnd()->metres(),
                'allowedToDate' => $row->getAllowedToDate()->metres(),
                'dailyBudget' => $row->getDailyBudget()->metres(),
                'driven' => $row->getDriven()->metres(),
            ];
        }

        return $figures;
    }

    private function insertRow(string $localDay): void
    {
        $contract = $this->contract();
        $midnight = CarbonImmutable::parse($localDay, 'UTC');
        $this->entityManager->persist(
            new ProjectionSnapshot(
                $contract,
                $midnight,
                $midnight,
                $midnight->addMinutes(5),
                $midnight->subHours(1),
                Distance::fromKilometres(1),
                Distance::fromKilometres(1),
                Distance::fromKilometres(1),
                Distance::fromKilometres(1),
                RiskLevel::Ok,
                Pace::fromMetresPerDay(1.0),
                null,
                Pace::fromMetresPerDay(1.0),
            )
        );
        $this->entityManager->flush();
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * @return list<RecalculateProjectionSnapshots> the recalculations queued so far
     */
    private function recalculations(): array
    {
        $messages = [];

        foreach ($this->asyncTransport()->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof RecalculateProjectionSnapshots) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    private static function data(
        int $allowance = 15_000,
        string $start = '2026-01-01',
        string $end = '2027-01-01',
        int $startOdometer = 0,
        string $excessCost = '0.12',
        int $tolerance = 0,
    ): LeaseContractData {
        $data = new LeaseContractData();
        $data->startDate = new \DateTimeImmutable($start);
        $data->endDate = new \DateTimeImmutable($end);
        $data->allowance = $allowance;
        $data->startOdometer = $startOdometer;
        $data->excessCostPerKm = $excessCost;
        $data->currency = Currency::CHF;
        $data->tolerancePercent = $tolerance;

        return $data;
    }
}
