<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Lease;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Account\Entity\User;
use Polaris\Account\Message\OwnerMidnightPassed;
use Polaris\Lease\Command\SnapshotCommand;
use Polaris\Lease\Domain\LeaseAssessment;
use Polaris\Lease\Domain\RiskLevel;
use Polaris\Lease\Entity\ProjectionSnapshot;
use Polaris\Lease\Form\Model\LeaseContractData;
use Polaris\Lease\Message\SaveProjectionSnapshot;
use Polaris\Lease\MessageHandler\QueueOwnerProjectionSnapshotsHandler;
use Polaris\Lease\MessageHandler\SaveProjectionSnapshotHandler;
use Polaris\Lease\Repository\LeaseContractRepository;
use Polaris\Lease\Repository\ProjectionSnapshotRepository;
use Polaris\Lease\Service\Dto\ProjectionSnapshotPoint;
use Polaris\Lease\Service\Enum\SnapshotOutcome;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Lease\Service\ProjectionHistoryService;
use Polaris\Lease\Service\ProjectionSnapshotService;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\OwnerMidnight;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[CoversClass(ProjectionSnapshotService::class)]
#[CoversClass(ProjectionHistoryService::class)]
#[CoversClass(ProjectionSnapshot::class)]
#[CoversClass(ProjectionSnapshotRepository::class)]
#[CoversClass(QueueOwnerProjectionSnapshotsHandler::class)]
#[CoversClass(SaveProjectionSnapshotHandler::class)]
#[CoversClass(SaveProjectionSnapshot::class)]
#[CoversClass(SnapshotCommand::class)]
#[CoversClass(OwnerMidnight::class)]
final class ProjectionSnapshotTest extends KernelTestCase
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

        // 10,000 km after 182.5 days of a one-year lease of 15,000 km: 20,000 km projected at the end.
        $this->vehicle = $this->vehicle('5YJ3E7EB2NF000090', 10_000);
        $this->leases->save($this->vehicle, self::data(15_000));
    }

    public function testASnapshotKeepsTheFiguresOfTheAssessment(): void
    {
        self::assertSame(SnapshotOutcome::Saved, $this->snapshots->save($this->vehicle, $this->midnight()));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('2026-07-03T00:00:00+00:00', $row->getLocalDay()->toIso8601String());
        self::assertSame('2026-07-03T00:00:00+00:00', $row->getCutoffAt()?->toIso8601String());
        self::assertSame('2026-07-03T00:05:00+00:00', $row->getComputedAt()->toIso8601String());
        self::assertSame('2026-07-02T12:00:00+00:00', $row->getAsOf()->toIso8601String());
        self::assertSame(10_000_000, $row->getDriven()->metres());
        self::assertSame(7_500_000, $row->getAllowedToDate()->metres());
        self::assertSame(20_000_000, $row->getProjectedAtEnd()->metres());
        // 5,000 km left over 182.5 days.
        self::assertSame(27_397, $row->getDailyBudget()->metres());
        self::assertSame(RiskLevel::Over, $row->getRiskLevel());
    }

    public function testTheSnapshotIsTakenAtTheLatestReadingNotAtTheClock(): void
    {
        self::mockTime('2026-07-05 08:30:00 UTC');

        self::assertSame(SnapshotOutcome::Saved, $this->snapshots->save($this->vehicle, $this->midnight('2026-07-05')));

        $row = $this->rows()[0];
        self::assertSame('2026-07-05T00:00:00+00:00', $row->getLocalDay()->toIso8601String());
        self::assertSame('2026-07-05T08:30:00+00:00', $row->getComputedAt()->toIso8601String());
        self::assertSame('2026-07-02T12:00:00+00:00', $row->getAsOf()->toIso8601String());
    }

    public function testRunningTwiceForTheSameMidnightLeavesOneRowAndTheLastWins(): void
    {
        $this->snapshots->save($this->vehicle, $this->midnight());
        $this->entityManager->persist(OdometerReading::manual($this->vehicle, CarbonImmutable::parse('2026-07-02 12:30:00', 'UTC'), Distance::fromKilometres(10_100)));
        $this->entityManager->flush();
        self::mockTime('2026-07-03 14:00:00 UTC');

        self::assertSame(SnapshotOutcome::Updated, $this->snapshots->save($this->vehicle, $this->midnight()));
        self::assertSame(SnapshotOutcome::Updated, $this->snapshots->save($this->vehicle, $this->midnight()));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame(10_100_000, $rows[0]->getDriven()->metres());
        self::assertSame('2026-07-03T14:00:00+00:00', $rows[0]->getComputedAt()->toIso8601String());
    }

    public function testTwoMidnightsGiveTwoRows(): void
    {
        $this->snapshots->save($this->vehicle, $this->midnight());
        self::mockTime('2026-07-04 00:05:00 UTC');

        self::assertSame(SnapshotOutcome::Saved, $this->snapshots->save($this->vehicle, $this->midnight('2026-07-04')));

        self::assertCount(2, $this->rows());
    }

    public function testNothingIsSavedWithoutDataOrWithoutAContract(): void
    {
        $noData = $this->vehicle('5YJ3E7EB2NF000091', null);
        $this->leases->save($noData, self::data(15_000));
        $noContract = $this->vehicle('5YJ3E7EB2NF000092', 5_000);

        self::assertSame(SnapshotOutcome::SkippedNoData, $this->snapshots->save($noData, $this->midnight()));
        self::assertSame(SnapshotOutcome::SkippedNoContract, $this->snapshots->save($noContract, $this->midnight()));

        self::assertSame([], $this->rows());
    }

    public function testAnEndedLeaseStillGetsASnapshotWithNoBudget(): void
    {
        self::mockTime('2027-02-01 00:05:00 UTC');

        self::assertSame(SnapshotOutcome::Saved, $this->snapshots->save($this->vehicle, $this->midnight('2027-02-01')));

        $row = $this->rows()[0];
        self::assertSame(0, $row->getDailyBudget()->metres());
        self::assertSame(RiskLevel::Over, $row->getRiskLevel());
        self::assertSame('2027-02-01T00:00:00+00:00', $row->getLocalDay()->toIso8601String());
    }

    public function testAReturnedLeaseGetsNoSnapshotButKeepsTheOnesItHas(): void
    {
        $this->snapshots->save($this->vehicle, $this->midnight());
        // Returned on 2 July: the car counts as returned from the start of the 3rd, which is the midnight of the 3rd.
        $this->leases->markReturned($this->vehicle, new \DateTimeImmutable('2026-07-02'));

        self::assertSame(SnapshotOutcome::SkippedReturned, $this->snapshots->save($this->vehicle, $this->midnight()));

        self::assertCount(1, $this->rows());
    }

    public function testReadingsThatContradictTheTermsAreSkippedNotFatal(): void
    {
        // An earlier reading with a higher odometer than the later one: the projection refuses it.
        $this->entityManager->persist(OdometerReading::manual($this->vehicle, CarbonImmutable::parse('2026-07-02 11:00:00', 'UTC'), Distance::fromKilometres(11_000)));
        $this->entityManager->flush();

        self::assertSame(SnapshotOutcome::SkippedNotAssessable, $this->snapshots->save($this->vehicle, $this->midnight()));
        self::assertSame([], $this->rows());
    }

    public function testASecondInsertOfTheDayDoesNotFailTheRun(): void
    {
        $this->snapshots->save($this->vehicle, $this->midnight());
        $container = self::getContainer();
        // A repository that misses the existing row, as if another writer stored it after the lookup.
        $blind = $this->createStub(ProjectionSnapshotRepository::class);
        $blind->method('findForDay')->willReturn(null);
        $service = new ProjectionSnapshotService($this->leases, $blind, $this->entityManager, $container->get(LockFactory::class), $container->get(ClockInterface::class));

        self::assertSame(SnapshotOutcome::Concurrent, $service->save($this->vehicle, $this->midnight()));
    }

    public function testASaveWhileAnotherRunsDoesNothing(): void
    {
        $contract = $this->leases->find($this->vehicle);
        \assert(null !== $contract);
        $lock = self::getContainer()->get(LockFactory::class)->createLock('lease_snapshot_' . $contract->getId()->toBase32(), 60);
        self::assertTrue($lock->acquire());

        try {
            self::assertSame(SnapshotOutcome::Busy, $this->snapshots->save($this->vehicle, $this->midnight()));
        } finally {
            $lock->release();
        }

        self::assertSame([], $this->rows());
        self::assertSame(SnapshotOutcome::Saved, $this->snapshots->save($this->vehicle, $this->midnight()), 'The lock was released.');
    }

    public function testTheHistoryIsOrderedByDayWithTypedFigures(): void
    {
        foreach (['2026-07-05', '2026-07-03', '2026-07-04'] as $day) {
            self::mockTime($day . ' 00:05:00 UTC');
            $this->snapshots->save($this->vehicle, $this->midnight($day));
        }

        $history = $this->history()->history($this->vehicle);

        self::assertSame(['2026-07-03', '2026-07-04', '2026-07-05'], $this->days($history));
        self::assertSame(10_000_000, $history[0]->driven->metres());
        self::assertSame(7_500_000, $history[0]->allowedToDate->metres());
        self::assertSame(20_000_000, $history[0]->projectedAtEnd->metres());
        self::assertSame(RiskLevel::Over, $history[0]->riskLevel);
        self::assertSame('2026-07-02T12:00:00+00:00', $history[0]->asOf->toIso8601String());
        self::assertSame('2026-07-03T00:05:00+00:00', $history[0]->computedAt->toIso8601String());
        self::assertSame('2026-07-03T00:00:00+00:00', $history[0]->cutoffAt?->toIso8601String());
        self::assertGreaterThan(0.0, $history[0]->blendedPace?->metresPerDay());
    }

    public function testTheHistoryBoundsAreInclusiveAndAVehicleWithoutAContractHasNone(): void
    {
        foreach (['2026-07-03', '2026-07-04', '2026-07-05'] as $day) {
            self::mockTime($day . ' 00:05:00 UTC');
            $this->snapshots->save($this->vehicle, $this->midnight($day));
        }
        $history = $this->history();

        $between = $history->history($this->vehicle, CarbonImmutable::parse('2026-07-04 15:00:00', 'UTC'), CarbonImmutable::parse('2026-07-05', 'UTC'));

        self::assertSame(['2026-07-04', '2026-07-05'], $this->days($between));
        self::assertSame([], $history->history($this->vehicle('5YJ3E7EB2NF000093', 1_000)));
    }

    public function testTheOwnersMidnightQueuesOneSaveForEveryLeaseOfThatOwnerOnly(): void
    {
        $sameOwner = $this->vehicle('5YJ3E7EB2NF000094', 2_000, $this->vehicle->getOwner());
        $this->leases->save($sameOwner, self::data(15_000));
        $stranger = $this->vehicle('5YJ3E7EB2NF000096', 2_000, self::createUser('other@example.test'));
        $this->leases->save($stranger, self::data(15_000));
        $this->asyncTransport();

        self::getContainer()->get(QueueOwnerProjectionSnapshotsHandler::class)(new OwnerMidnightPassed(
            $this->vehicle->getOwner()->getId()->toRfc4122(),
            '2026-07-03',
            CarbonImmutable::parse('2026-07-03 00:00:00', 'UTC')->getTimestamp(),
        ));

        $sent = $this->sent();
        self::assertContainsOnlyInstancesOf(SaveProjectionSnapshot::class, $sent);
        $queuedFor = [];

        foreach ($sent as $save) {
            $queuedFor[] = $save->reportingVehicleId();
        }

        $expected = [$this->vehicle->getId()->toRfc4122(), $sameOwner->getId()->toRfc4122()];
        sort($queuedFor);
        sort($expected);
        self::assertSame($expected, $queuedFor, 'The owner\'s two leases, not the stranger\'s.');
        self::assertSame('2026-07-03', $sent[0]->localDay);
        self::assertSame(CarbonImmutable::parse('2026-07-03 00:00:00', 'UTC')->getTimestamp(), $sent[0]->midnightTimestamp);
    }

    public function testTheSaveHandlerSavesQueuesABusyOneOnceMoreAndIgnoresAnUnknownVehicle(): void
    {
        $handler = self::getContainer()->get(SaveProjectionSnapshotHandler::class);
        $this->asyncTransport();

        $handler(new SaveProjectionSnapshot($this->vehicle->getId()->toRfc4122(), '2026-07-03', $this->midnight()->at->getTimestamp()));
        $handler(new SaveProjectionSnapshot('01JZZZZZZZZZZZZZZZZZZZZZZZ', '2026-07-03', $this->midnight()->at->getTimestamp()));
        self::assertCount(1, $this->rows());
        self::assertSame([], $this->sent());

        $contract = $this->leases->find($this->vehicle);
        \assert(null !== $contract);
        $lock = self::getContainer()->get(LockFactory::class)->createLock('lease_snapshot_' . $contract->getId()->toBase32(), 60);
        self::assertTrue($lock->acquire());

        try {
            $handler(new SaveProjectionSnapshot($this->vehicle->getId()->toRfc4122(), '2026-07-03', $this->midnight()->at->getTimestamp()));
            self::assertCount(1, $this->sent());
            $retry = $this->sent()[0];
            self::assertInstanceOf(SaveProjectionSnapshot::class, $retry);
            self::assertSame(1, $retry->attempt);

            $handler($retry);
            self::assertCount(1, $this->sent(), 'Queued once more only.');
        } finally {
            $lock->release();
        }
    }

    public function testTheCommandSavesEveryLeaseAndSummarisesAndAnotherRunUpdates(): void
    {
        $noData = $this->vehicle('5YJ3E7EB2NF000095', null);
        $this->leases->save($noData, self::data(15_000));
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('1 saved, 0 already there (updated), 1 skipped (1 no data).', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('0 saved, 1 already there (updated), 1 skipped (1 no data).', $tester->getDisplay());
        self::assertCount(1, $this->rows());
    }

    public function testTheCommandTakesOneVehicleByVinAndRefusesAnUnknownOne(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--vin' => strtolower('5YJ3E7EB2NF000090')]));
        self::assertStringContainsString('1 saved, 0 already there (updated), 0 skipped.', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute(['--vin' => '5YJ3E7EB2NF999999']));
        self::assertStringContainsString('No vehicle with VIN 5YJ3E7EB2NF999999.', $tester->getDisplay());
    }

    public function testOnlyTheReadingsUpToTheMidnightCount(): void
    {
        $this->entityManager->persist(OdometerReading::manual($this->vehicle, CarbonImmutable::parse('2026-07-02 23:59:59', 'UTC'), Distance::fromKilometres(10_050)));
        $this->entityManager->persist(OdometerReading::manual($this->vehicle, CarbonImmutable::parse('2026-07-03 00:00:01', 'UTC'), Distance::fromKilometres(10_100)));
        $this->entityManager->flush();

        // The job runs late, after both readings are stored: the figures are still those of the midnight.
        self::mockTime('2026-07-03 09:00:00 UTC');
        $this->snapshots->save($this->vehicle, $this->midnight());

        $row = $this->rows()[0];
        self::assertSame(10_050_000, $row->getDriven()->metres());
        self::assertSame('2026-07-02T23:59:59+00:00', $row->getAsOf()->toIso8601String());
        self::assertSame('2026-07-03T09:00:00+00:00', $row->getComputedAt()->toIso8601String());
    }

    public function testAReadingAtTheMidnightItselfCounts(): void
    {
        $this->entityManager->persist(OdometerReading::manual($this->vehicle, CarbonImmutable::parse('2026-07-03 00:00:00', 'UTC'), Distance::fromKilometres(10_100)));
        $this->entityManager->flush();

        $this->snapshots->save($this->vehicle, $this->midnight());

        self::assertSame(10_100_000, $this->rows()[0]->getDriven()->metres());
    }

    public function testAConfirmationRunningPastTheMidnightIsCutThere(): void
    {
        $reading = $this->entityManager->getRepository(OdometerReading::class)->findOneBy(['vehicle' => $this->vehicle]);
        \assert($reading instanceof OdometerReading);
        self::mockTime('2026-07-03 09:00:00 UTC');
        $reading->confirm(CarbonImmutable::parse('2026-07-03 08:00:00', 'UTC'));
        $this->entityManager->flush();

        $this->snapshots->save($this->vehicle, $this->midnight());

        $row = $this->rows()[0];
        self::assertSame('2026-07-03T00:00:00+00:00', $row->getAsOf()->toIso8601String(), 'The car still had that odometer at the midnight, not after it.');
        self::assertSame(10_000_000, $row->getDriven()->metres());
    }

    public function testTheSnapshotStoresThePaceTheProjectionUsed(): void
    {
        $this->snapshots->save($this->vehicle, $this->midnight());

        $assessment = $this->leases->assessAt($this->vehicle, $this->midnight()->at);
        self::assertInstanceOf(LeaseAssessment::class, $assessment);
        $row = $this->rows()[0];
        // The driver binds a float with 14 significant digits (PHP `precision`), so the stored pace is equal to within 1e-9 m/day.
        self::assertEqualsWithDelta($assessment->projection->blendedPace->metresPerDay(), $row->getBlendedPace()?->metresPerDay(), 1e-6);
        self::assertEqualsWithDelta($assessment->projection->overallPace->metresPerDay(), $row->getOverallPace()?->metresPerDay(), 1e-6);
        self::assertEqualsWithDelta($assessment->projection->recentPace?->metresPerDay(), $row->getRecentPace()?->metresPerDay(), 1e-6);
        self::assertNotNull($row->getRecentPace(), 'More than 30 days into the lease.');
    }

    public function testThereIsNoRecentPaceBeforeThirtyDaysOfLease(): void
    {
        $young = $this->vehicle('5YJ3E7EB2NF000097', null);
        $data = self::data(15_000);
        $data->startDate = new \DateTimeImmutable('2026-06-20');
        $data->endDate = new \DateTimeImmutable('2027-06-20');
        $this->leases->save($young, $data);
        $this->entityManager->persist(OdometerReading::manual($young, CarbonImmutable::parse('2026-07-02 12:00:00', 'UTC'), Distance::fromKilometres(500)));
        $this->entityManager->flush();

        self::assertSame(SnapshotOutcome::Saved, $this->snapshots->save($young, $this->midnight()));

        $contract = $this->leases->find($young);
        $row = $this->entityManager->getRepository(ProjectionSnapshot::class)->findOneBy(['leaseContract' => $contract]);
        self::assertInstanceOf(ProjectionSnapshot::class, $row);
        self::assertNull($row->getRecentPace());
        self::assertEqualsWithDelta($row->getOverallPace()?->metresPerDay(), $row->getBlendedPace()?->metresPerDay(), 1e-6);
    }

    public function testAReturnAfterTheMidnightDoesNotStopThatMidnightFromBeingSnapshotted(): void
    {
        // Returned on the 2nd (counts as returned from the start of the 3rd); the snapshot of the midnight that opens the 2nd is late.
        $this->entityManager->persist(OdometerReading::manual($this->vehicle, CarbonImmutable::parse('2026-07-01 12:00:00', 'UTC'), Distance::fromKilometres(5_000)));
        $this->entityManager->flush();
        $this->leases->markReturned($this->vehicle, new \DateTimeImmutable('2026-07-02'));

        self::assertSame(SnapshotOutcome::Saved, $this->snapshots->save($this->vehicle, $this->midnight('2026-07-02')));

        $row = $this->rows()[0];
        self::assertSame('2026-07-02T00:00:00+00:00', $row->getLocalDay()->toIso8601String());
        self::assertSame(5_000_000, $row->getDriven()->metres());
    }

    public function testTheLocalDayAndTheCutoffFollowTheOwnersTimeZone(): void
    {
        // Midnight of the 3rd in Zurich (UTC+2 in summer) is 22:00 UTC on the 2nd; the reading of 12:00 UTC on the 2nd is before it.
        $midnight = $this->midnight('2026-07-03', 'Europe/Zurich');

        $this->snapshots->save($this->vehicle, $midnight);

        $row = $this->rows()[0];
        self::assertSame('2026-07-03T00:00:00+00:00', $row->getLocalDay()->toIso8601String());
        self::assertSame('2026-07-02T22:00:00+00:00', $row->getCutoffAt()?->toIso8601String());
        self::assertSame(10_000_000, $row->getDriven()->metres());
    }

    /**
     * The midnight that opens $day (UTC owners in these tests, unless a test says otherwise).
     */
    private function midnight(string $day = '2026-07-03', string $timeZone = 'UTC'): OwnerMidnight
    {
        return OwnerMidnight::opening(CarbonImmutable::parse($day, 'UTC'), $timeZone);
    }

    private function history(): ProjectionHistoryService
    {
        $container = self::getContainer();

        return new ProjectionHistoryService($container->get(LeaseContractRepository::class), $container->get(ProjectionSnapshotRepository::class));
    }

    private function tester(): CommandTester
    {
        $kernel = self::bootKernel();

        return new CommandTester(new Application($kernel)->find('polaris:lease:snapshot'));
    }

    /**
     * @return list<object> the messages queued on the async transport
     */
    private function sent(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $messages = [];

        foreach ($transport->getSent() as $envelope) {
            $messages[] = $envelope->getMessage();
        }

        return $messages;
    }

    /**
     * @param list<ProjectionSnapshotPoint> $points
     *
     * @return list<string> the local days (`Y-m-d`) of the points, in order
     */
    private function days(array $points): array
    {
        $days = [];

        foreach ($points as $point) {
            $days[] = $point->localDay->format('Y-m-d');
        }

        return $days;
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        return $transport;
    }

    private function vehicle(string $vin, ?int $odometerKm, ?User $owner = null): Vehicle
    {
        $vehicle = new Vehicle(new Vin($vin), 'Model 3', $owner ?? self::createUser());
        $this->entityManager->persist($vehicle);

        if (null !== $odometerKm) {
            $this->entityManager->persist(OdometerReading::manual($vehicle, CarbonImmutable::parse('2026-07-02 12:00:00', 'UTC'), Distance::fromKilometres($odometerKm)));
        }

        $this->entityManager->flush();

        return $vehicle;
    }

    /**
     * @return list<ProjectionSnapshot>
     */
    private function rows(): array
    {
        $this->entityManager->clear();
        $vehicle = $this->entityManager->find(Vehicle::class, $this->vehicle->getId());
        \assert($vehicle instanceof Vehicle);
        $this->vehicle = $vehicle;

        return $this->entityManager->getRepository(ProjectionSnapshot::class)->findBy([], ['localDay' => 'ASC']);
    }

    private static function data(int $allowancePerYear): LeaseContractData
    {
        $data = new LeaseContractData();
        $data->startDate = new \DateTimeImmutable('2026-01-01');
        $data->endDate = new \DateTimeImmutable('2027-01-01');
        $data->allowance = $allowancePerYear;
        $data->startOdometer = 0;
        $data->excessCostPerKm = '0.12';
        $data->currency = Currency::CHF;
        $data->tolerancePercent = 0;

        return $data;
    }
}
