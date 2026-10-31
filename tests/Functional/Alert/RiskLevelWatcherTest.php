<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Alert;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Alert\Entity\AlertLog;
use Polaris\Alert\Entity\AlertType;
use Polaris\Alert\Message\CheckRiskLevel;
use Polaris\Alert\MessageHandler\CheckRiskLevelHandler;
use Polaris\Alert\Repository\AlertLogRepository;
use Polaris\Alert\Service\Dto\RiskLevelChange;
use Polaris\Alert\Service\Enum\RiskCheckOutcome;
use Polaris\Alert\Service\RiskLevelWatcher;
use Polaris\Extension\NullRiskAlertNotifier;
use Polaris\Extension\RiskAlertNotifier;
use Polaris\Lease\Domain\LeaseAssessment;
use Polaris\Lease\Domain\RiskLevel;
use Polaris\Lease\Form\Model\LeaseContractData;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Tests\Support\RecordingRiskAlertNotifier;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[CoversClass(RiskLevelWatcher::class)]
#[CoversClass(CheckRiskLevelHandler::class)]
#[CoversClass(AlertLogRepository::class)]
#[CoversClass(AlertLog::class)]
#[CoversClass(NullRiskAlertNotifier::class)]
final class RiskLevelWatcherTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SignsIn;

    private EntityManagerInterface $entityManager;
    private LeaseTermsService $leases;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-07-02 12:00:00 UTC');
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->leases = self::getContainer()->get(LeaseTermsService::class);

        $this->vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000090'), 'Model 3', self::createUser());
        $this->entityManager->persist($this->vehicle);
        // 10,000 km after 182 days of a one-year lease: about 20,000 km projected at the end.
        $this->entityManager->persist(OdometerReading::manual($this->vehicle, CarbonImmutable::parse('2026-07-02 12:00:00', 'UTC'), Distance::fromKilometres(10_000)));
        $this->entityManager->flush();
    }

    public function testWithoutAContractNothingIsAssessedNorLogged(): void
    {
        $notifier = new RecordingRiskAlertNotifier();

        self::assertSame(RiskCheckOutcome::NotAssessable, $this->watcher($notifier)->check($this->vehicle));
        self::assertSame([], $this->logs());
        self::assertSame([], $notifier->changes);
    }

    public function testTheFirstAssessmentIsRecordedWithoutTellingAnybody(): void
    {
        $this->leases->save($this->vehicle, self::data(15_000));
        $notifier = new RecordingRiskAlertNotifier();

        self::assertSame(RiskCheckOutcome::Recorded, $this->watcher($notifier)->check($this->vehicle));

        $logs = $this->logs();
        self::assertCount(1, $logs);
        self::assertSame(AlertType::Baseline, $logs[0]->getType());
        self::assertSame(RiskLevel::Over, $logs[0]->getRiskLevel());
        self::assertNull($logs[0]->getChannel());
        self::assertSame('2026-07-02T12:00:00+00:00', $logs[0]->getSentAt()->toIso8601String());
        self::assertSame([], $notifier->changes);
    }

    public function testTheSameLevelIsNeverToldTwice(): void
    {
        $this->leases->save($this->vehicle, self::data(30_000));
        $notifier = new RecordingRiskAlertNotifier();
        $watcher = $this->watcher($notifier);
        $watcher->check($this->vehicle);

        self::assertSame(RiskCheckOutcome::Unchanged, $watcher->check($this->vehicle));
        self::assertSame(RiskCheckOutcome::Unchanged, $watcher->check($this->vehicle));

        self::assertCount(1, $this->logs());
        self::assertSame([], $notifier->changes);
    }

    public function testAWorseLevelIsToldOnceWithTheAssessment(): void
    {
        $notifier = new RecordingRiskAlertNotifier('mail');
        $watcher = $this->watcher($notifier);
        $this->leases->save($this->vehicle, self::data(30_000));
        $watcher->check($this->vehicle);
        $this->leases->save($this->vehicle, self::data(15_000));

        self::assertSame(RiskCheckOutcome::Notified, $watcher->check($this->vehicle));
        self::assertSame(RiskCheckOutcome::Unchanged, $watcher->check($this->vehicle), 'The second check finds the row of the first.');

        self::assertCount(1, $notifier->changes);
        $change = $notifier->changes[0];
        self::assertSame($this->vehicle, $change->vehicle);
        self::assertSame(RiskLevel::Ok, $change->previous);
        self::assertSame(RiskLevel::Over, $change->current);
        self::assertSame(RiskLevel::Over, $change->assessment->riskLevel);
        self::assertNotNull($change->assessment->weeklyCut);
        $latest = self::getContainer()->get(AlertLogRepository::class)->findLatest($this->vehicle);
        self::assertInstanceOf(AlertLog::class, $latest);
        self::assertSame(AlertType::RiskIncreased, $latest->getType());
        self::assertSame(RiskLevel::Over, $latest->getRiskLevel());
        self::assertSame('mail', $latest->getChannel());
        self::assertCount(2, $this->logs());
    }

    public function testOkToWatchIsAWorseLevelToo(): void
    {
        $notifier = new RecordingRiskAlertNotifier();
        $watcher = $this->watcher($notifier);
        $this->leases->save($this->vehicle, self::data(30_000));
        $watcher->check($this->vehicle);
        // About 20,000 km projected against 21,000 with a tolerance of 10 %: above 18,900, below the allowance.
        $this->leases->save($this->vehicle, self::data(21_000, 10));

        self::assertSame(RiskCheckOutcome::Notified, $watcher->check($this->vehicle));
        self::assertSame(RiskLevel::Watch, $notifier->changes[0]->current);
    }

    public function testALevelThatWentDownIsRecordedAndTheNextRiseIsToldAgain(): void
    {
        $this->leases->save($this->vehicle, self::data(15_000));
        $this->watcher(new RecordingRiskAlertNotifier())->check($this->vehicle);
        $this->leases->save($this->vehicle, self::data(30_000));
        $silent = new RecordingRiskAlertNotifier();

        self::assertSame(RiskCheckOutcome::Recorded, $this->watcher($silent)->check($this->vehicle));
        self::assertSame([], $silent->changes);

        $this->leases->save($this->vehicle, self::data(15_000));
        $told = new RecordingRiskAlertNotifier();

        self::assertSame(RiskCheckOutcome::Notified, $this->watcher($told)->check($this->vehicle));
        self::assertSame(RiskLevel::Ok, $told->changes[0]->previous);
    }

    public function testAnEndedLeaseIsNotAssessed(): void
    {
        $this->leases->save($this->vehicle, self::data(15_000));
        self::mockTime('2027-02-01 12:00:00 UTC');
        $notifier = new RecordingRiskAlertNotifier();

        self::assertSame(RiskCheckOutcome::NotAssessable, $this->watcher($notifier)->check($this->vehicle));
        self::assertSame([], $this->logs());
        self::assertSame([], $notifier->changes);
    }

    public function testACheckWhileAnotherRunsDoesNothing(): void
    {
        $this->leases->save($this->vehicle, self::data(15_000));
        $notifier = new RecordingRiskAlertNotifier();
        $lock = $this->lock();
        self::assertTrue($lock->acquire());

        try {
            self::assertSame(RiskCheckOutcome::Busy, $this->watcher($notifier)->check($this->vehicle));
        } finally {
            $lock->release();
        }

        self::assertSame([], $this->logs());
        self::assertSame(RiskCheckOutcome::Recorded, $this->watcher($notifier)->check($this->vehicle), 'The lock was released.');
    }

    public function testTheDefaultNotifierSendsNothingButTheRiseIsStillLogged(): void
    {
        $this->leases->save($this->vehicle, self::data(30_000));
        $assessment = $this->leases->assess($this->vehicle);
        self::assertInstanceOf(LeaseAssessment::class, $assessment);
        self::assertNull(new NullRiskAlertNotifier()->notify(new RiskLevelChange($this->vehicle, RiskLevel::Ok, RiskLevel::Over, $assessment)));

        $handler = self::getContainer()->get(CheckRiskLevelHandler::class);
        $id = $this->vehicle->getId()->toRfc4122();
        $handler(new CheckRiskLevel($id));
        $this->leases->save($this->vehicle, self::data(15_000));
        $handler(new CheckRiskLevel($id));

        $logs = $this->logs();
        self::assertCount(2, $logs);
        self::assertSame(AlertType::RiskIncreased, $logs[1]->getType());
        self::assertNull($logs[1]->getChannel(), 'Nothing was sent, so no channel.');
    }

    public function testTheHandlerQueuesABusyCheckOnceMore(): void
    {
        $this->leases->save($this->vehicle, self::data(15_000));
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();
        $handler = self::getContainer()->get(CheckRiskLevelHandler::class);
        $lock = $this->lock();
        self::assertTrue($lock->acquire());

        try {
            $handler(new CheckRiskLevel($this->vehicle->getId()->toRfc4122()));
            self::assertCount(1, $transport->getSent());
            $retry = $transport->getSent()[0]->getMessage();
            self::assertInstanceOf(CheckRiskLevel::class, $retry);
            self::assertSame(1, $retry->attempt);

            $handler($retry);
            self::assertCount(1, $transport->getSent(), 'Queued once more only.');
        } finally {
            $lock->release();
        }
    }

    public function testAVehicleThatNoLongerExistsIsIgnored(): void
    {
        $handler = self::getContainer()->get(CheckRiskLevelHandler::class);

        $handler(new CheckRiskLevel('01JZZZZZZZZZZZZZZZZZZZZZZZ'));

        self::assertSame([], $this->logs());
    }

    private function lock(): \Symfony\Component\Lock\SharedLockInterface
    {
        return self::getContainer()->get(LockFactory::class)->createLock('risk_alert_' . $this->vehicle->getId()->toBase32(), 60);
    }

    private function watcher(RiskAlertNotifier $notifier): RiskLevelWatcher
    {
        $container = self::getContainer();

        return new RiskLevelWatcher(
            $this->leases,
            $container->get(AlertLogRepository::class),
            $this->entityManager,
            $notifier,
            $container->get(LockFactory::class),
            $container->get(ClockInterface::class),
        );
    }

    /**
     * @return list<AlertLog>
     */
    private function logs(): array
    {
        $this->entityManager->clear();
        $vehicle = $this->entityManager->find(Vehicle::class, $this->vehicle->getId());
        \assert($vehicle instanceof Vehicle);
        $this->vehicle = $vehicle;

        return $this->entityManager->getRepository(AlertLog::class)->findBy([], ['sentAt' => 'ASC', 'id' => 'ASC']);
    }

    private static function data(int $allowancePerYear, int $tolerancePercent = 0): LeaseContractData
    {
        $data = new LeaseContractData();
        $data->startDate = new \DateTimeImmutable('2026-01-01');
        $data->endDate = new \DateTimeImmutable('2027-01-01');
        $data->allowance = $allowancePerYear;
        $data->startOdometer = 0;
        $data->excessCostPerKm = '0.12';
        $data->currency = Currency::CHF;
        $data->tolerancePercent = $tolerancePercent;

        return $data;
    }
}
