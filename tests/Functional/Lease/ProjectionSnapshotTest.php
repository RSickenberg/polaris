<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Lease;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Fetching\Scheduler\FetchScheduleProvider;
use Polaris\Lease\Command\SnapshotCommand;
use Polaris\Lease\Domain\RiskLevel;
use Polaris\Lease\Entity\ProjectionSnapshot;
use Polaris\Lease\Form\Model\LeaseContractData;
use Polaris\Lease\Message\QueueProjectionSnapshots;
use Polaris\Lease\Message\SaveProjectionSnapshot;
use Polaris\Lease\MessageHandler\QueueProjectionSnapshotsHandler;
use Polaris\Lease\MessageHandler\SaveProjectionSnapshotHandler;
use Polaris\Lease\Repository\LeaseContractRepository;
use Polaris\Lease\Repository\ProjectionSnapshotRepository;
use Polaris\Lease\Service\Enum\SnapshotOutcome;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Lease\Service\ProjectionHistoryService;
use Polaris\Lease\Service\ProjectionSnapshotService;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
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
use Symfony\Component\Scheduler\RecurringMessage;

#[CoversClass(ProjectionSnapshotService::class)]
#[CoversClass(ProjectionHistoryService::class)]
#[CoversClass(ProjectionSnapshot::class)]
#[CoversClass(ProjectionSnapshotRepository::class)]
#[CoversClass(QueueProjectionSnapshotsHandler::class)]
#[CoversClass(SaveProjectionSnapshotHandler::class)]
#[CoversClass(SaveProjectionSnapshot::class)]
#[CoversClass(SnapshotCommand::class)]
#[CoversClass(FetchScheduleProvider::class)]
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
        self::mockTime('2026-07-02 12:00:00 UTC');
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
        self::assertSame(SnapshotOutcome::Saved, $this->snapshots->save($this->vehicle));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('2026-07-02T00:00:00+00:00', $row->getDay()->toIso8601String());
        self::assertSame('2026-07-02T12:00:00+00:00', $row->getComputedAt()->toIso8601String());
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

        self::assertSame(SnapshotOutcome::Saved, $this->snapshots->save($this->vehicle));

        $row = $this->rows()[0];
        self::assertSame('2026-07-05T00:00:00+00:00', $row->getDay()->toIso8601String());
        self::assertSame('2026-07-05T08:30:00+00:00', $row->getComputedAt()->toIso8601String());
        self::assertSame('2026-07-02T12:00:00+00:00', $row->getAsOf()->toIso8601String());
    }

    public function testRunningTwiceTheSameDayLeavesOneRowAndTheLastWins(): void
    {
        $this->snapshots->save($this->vehicle);
        $this->entityManager->persist(OdometerReading::manual($this->vehicle, CarbonImmutable::parse('2026-07-02 12:30:00', 'UTC'), Distance::fromKilometres(10_100)));
        $this->entityManager->flush();
        self::mockTime('2026-07-02 14:00:00 UTC');

        self::assertSame(SnapshotOutcome::Updated, $this->snapshots->save($this->vehicle));
        self::assertSame(SnapshotOutcome::Updated, $this->snapshots->save($this->vehicle));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame(10_100_000, $rows[0]->getDriven()->metres());
        self::assertSame('2026-07-02T14:00:00+00:00', $rows[0]->getComputedAt()->toIso8601String());
    }

    public function testTwoUtcDaysGiveTwoRows(): void
    {
        $this->snapshots->save($this->vehicle);
        self::mockTime('2026-07-03 00:05:00 UTC');

        self::assertSame(SnapshotOutcome::Saved, $this->snapshots->save($this->vehicle));

        self::assertCount(2, $this->rows());
    }

    public function testNothingIsSavedWithoutDataOrWithoutAContract(): void
    {
        $noData = $this->vehicle('5YJ3E7EB2NF000091', null);
        $this->leases->save($noData, self::data(15_000));
        $noContract = $this->vehicle('5YJ3E7EB2NF000092', 5_000);

        self::assertSame(SnapshotOutcome::SkippedNoData, $this->snapshots->save($noData));
        self::assertSame(SnapshotOutcome::SkippedNoContract, $this->snapshots->save($noContract));

        self::assertSame([], $this->rows());
    }

    public function testAnEndedLeaseStillGetsASnapshotWithNoBudget(): void
    {
        self::mockTime('2027-02-01 12:00:00 UTC');

        self::assertSame(SnapshotOutcome::Saved, $this->snapshots->save($this->vehicle));

        $row = $this->rows()[0];
        self::assertSame(0, $row->getDailyBudget()->metres());
        self::assertSame(RiskLevel::Over, $row->getRiskLevel());
        self::assertSame('2027-02-01T00:00:00+00:00', $row->getDay()->toIso8601String());
    }

    public function testAReturnedLeaseGetsNoSnapshotButKeepsTheOnesItHas(): void
    {
        $this->snapshots->save($this->vehicle);
        $this->leases->markReturned($this->vehicle, new \DateTimeImmutable('2026-07-02'));
        self::mockTime('2026-07-03 12:00:00 UTC');

        self::assertSame(SnapshotOutcome::SkippedReturned, $this->snapshots->save($this->vehicle));

        self::assertCount(1, $this->rows());
    }

    public function testReadingsThatContradictTheTermsAreSkippedNotFatal(): void
    {
        // A reading after the clock: the projection refuses it.
        $this->entityManager->persist(OdometerReading::manual($this->vehicle, CarbonImmutable::parse('2026-07-09 12:00:00', 'UTC'), Distance::fromKilometres(11_000)));
        $this->entityManager->flush();

        self::assertSame(SnapshotOutcome::SkippedNotAssessable, $this->snapshots->save($this->vehicle));
        self::assertSame([], $this->rows());
    }

    public function testASecondInsertOfTheDayDoesNotFailTheRun(): void
    {
        $this->snapshots->save($this->vehicle);
        $container = self::getContainer();
        // A repository that misses the existing row, as if another writer stored it after the lookup.
        $blind = new class($container->get('doctrine')) extends ProjectionSnapshotRepository {
            public function findForDay(\Polaris\Lease\Entity\LeaseContract $contract, CarbonImmutable $day): ?ProjectionSnapshot
            {
                return null;
            }
        };
        $service = new ProjectionSnapshotService($this->leases, $blind, $this->entityManager, $container->get(LockFactory::class), $container->get(ClockInterface::class));

        self::assertSame(SnapshotOutcome::Concurrent, $service->save($this->vehicle));
    }

    public function testASaveWhileAnotherRunsDoesNothing(): void
    {
        $contract = $this->leases->find($this->vehicle);
        \assert(null !== $contract);
        $lock = self::getContainer()->get(LockFactory::class)->createLock('lease_snapshot_' . $contract->getId()->toBase32(), 60);
        self::assertTrue($lock->acquire());

        try {
            self::assertSame(SnapshotOutcome::Busy, $this->snapshots->save($this->vehicle));
        } finally {
            $lock->release();
        }

        self::assertSame([], $this->rows());
        self::assertSame(SnapshotOutcome::Saved, $this->snapshots->save($this->vehicle), 'The lock was released.');
    }

    public function testTheHistoryIsOrderedByDayWithTypedFigures(): void
    {
        foreach (['2026-07-04 23:50:00', '2026-07-02 23:50:00', '2026-07-03 23:50:00'] as $at) {
            self::mockTime($at . ' UTC');
            $this->snapshots->save($this->vehicle);
        }

        $history = $this->history()->history($this->vehicle);

        self::assertSame(
            ['2026-07-02', '2026-07-03', '2026-07-04'],
            array_map(static fn ($point): string => $point->day->format('Y-m-d'), $history),
        );
        self::assertSame(10_000_000, $history[0]->driven->metres());
        self::assertSame(7_500_000, $history[0]->allowedToDate->metres());
        self::assertSame(20_000_000, $history[0]->projectedAtEnd->metres());
        self::assertSame(RiskLevel::Over, $history[0]->riskLevel);
        self::assertSame('2026-07-02T12:00:00+00:00', $history[0]->asOf->toIso8601String());
        self::assertSame('2026-07-02T23:50:00+00:00', $history[0]->computedAt->toIso8601String());
    }

    public function testTheHistoryBoundsAreInclusiveAndAVehicleWithoutAContractHasNone(): void
    {
        foreach (['2026-07-02', '2026-07-03', '2026-07-04'] as $day) {
            self::mockTime($day . ' 23:50:00 UTC');
            $this->snapshots->save($this->vehicle);
        }
        $history = $this->history();

        $days = array_map(
            static fn ($point): string => $point->day->format('Y-m-d'),
            $history->history($this->vehicle, CarbonImmutable::parse('2026-07-03 15:00:00', 'UTC'), CarbonImmutable::parse('2026-07-04', 'UTC')),
        );

        self::assertSame(['2026-07-03', '2026-07-04'], $days);
        self::assertSame([], $history->history($this->vehicle('5YJ3E7EB2NF000093', 1_000)));
    }

    public function testTheDailyTickQueuesOneSaveForEveryLease(): void
    {
        $other = $this->vehicle('5YJ3E7EB2NF000094', 2_000);
        $this->leases->save($other, self::data(15_000));
        $this->asyncTransport();

        self::getContainer()->get(QueueProjectionSnapshotsHandler::class)(new QueueProjectionSnapshots());

        $ids = array_map(
            static fn (object $message): string => $message instanceof SaveProjectionSnapshot ? $message->reportingVehicleId() : '',
            $this->sent(),
        );
        sort($ids);
        $expected = [$this->vehicle->getId()->toRfc4122(), $other->getId()->toRfc4122()];
        sort($expected);
        self::assertSame($expected, $ids);
    }

    public function testTheSaveHandlerSavesQueuesABusyOneOnceMoreAndIgnoresAnUnknownVehicle(): void
    {
        $handler = self::getContainer()->get(SaveProjectionSnapshotHandler::class);
        $this->asyncTransport();

        $handler(new SaveProjectionSnapshot($this->vehicle->getId()->toRfc4122()));
        $handler(new SaveProjectionSnapshot('01JZZZZZZZZZZZZZZZZZZZZZZZ'));
        self::assertCount(1, $this->rows());
        self::assertSame([], $this->sent());

        $contract = $this->leases->find($this->vehicle);
        \assert(null !== $contract);
        $lock = self::getContainer()->get(LockFactory::class)->createLock('lease_snapshot_' . $contract->getId()->toBase32(), 60);
        self::assertTrue($lock->acquire());

        try {
            $handler(new SaveProjectionSnapshot($this->vehicle->getId()->toRfc4122()));
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

    public function testTheDailyScheduleListsTheSnapshotTick(): void
    {
        $recurring = self::getContainer()->get(FetchScheduleProvider::class)->getSchedule()->getRecurringMessages();

        $triggers = array_map(static fn (RecurringMessage $message): string => (string) $message->getTrigger(), $recurring);

        self::assertCount(1, array_filter($triggers, static fn (string $trigger): bool => str_contains($trigger, FetchScheduleProvider::PROJECTION_SNAPSHOTS)));
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

        return array_values(array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()));
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        return $transport;
    }

    private function vehicle(string $vin, ?int $odometerKm): Vehicle
    {
        $vehicle = new Vehicle(new Vin($vin), 'Model 3', self::createUser());
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

        return $this->entityManager->getRepository(ProjectionSnapshot::class)->findBy([], ['day' => 'ASC']);
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
