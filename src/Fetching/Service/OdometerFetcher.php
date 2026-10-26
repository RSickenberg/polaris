<?php

declare(strict_types=1);

namespace Polaris\Fetching\Service;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Fetching\Domain\FetchWindow;
use Polaris\Fetching\Domain\RefreshOutcome;
use Polaris\Fetching\Domain\SlotRunStatus;
use Polaris\Fetching\Entity\FetchSlotRun;
use Polaris\Fetching\Entity\RefreshAttempt;
use Polaris\Fetching\Message\FetchOdometerMessage;
use Polaris\Fetching\Repository\FetchSlotRunRepository;
use Polaris\Fetching\Repository\RefreshAttemptRepository;
use Polaris\Reading\Service\Enum\RecordOutcome;
use Polaris\Reading\Service\OdometerReadingService;
use Polaris\Tesla\Service\Enum\NoReadingReason;
use Polaris\Tesla\Service\Exception\FleetApiError;
use Polaris\Tesla\Service\Exception\TeslaNotConnected;
use Polaris\Tesla\Service\TeslaAppSettings;
use Polaris\Tesla\Service\TeslaOdometerReader;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Ulid;

/**
 * Runs one {@see FetchOdometerMessage}: asks {@see TeslaOdometerReader} (which never wakes the car) for
 * the odometer, stores it when it changed, and, when the car is not online, checks again an hour later
 * until the window of the slot closes. The checks stay on the hours after the slot, not an hour after the previous one. Messenger's own retry is off for this message: a billed call is
 * never repeated by it, only by the hourly rule here.
 *
 * One fetch runs per vehicle at a time, scheduled or manual, under a lock. A manual refresh that finds it held
 * ends as {@see RefreshOutcome::Busy}; a scheduled check is queued again shortly, without using up a state check.
 * A message with a refresh id settles that {@see RefreshAttempt}.
 */
final readonly class OdometerFetcher
{
    /**
     * Longer than a read can take (state check and live read, each with two retries capped at 4 s), so a
     * lock left by a killed worker frees itself soon.
     */
    private const int LOCK_TTL_SECONDS = 60;

    /**
     * How long a scheduled check waits when another fetch of the vehicle holds the lock.
     */
    private const int BUSY_DELAY_SECONDS = 30;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private FetchSlotRunRepository $runs,
        private RefreshAttemptRepository $attempts,
        private LockFactory $lockFactory,
        private SlotRunLedger $ledger,
        private TeslaAppSettings $teslaApps,
        private TeslaOdometerReader $reader,
        private OdometerReadingService $readings,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return SlotRunStatus how the fetch ended, or {@see SlotRunStatus::Retrying} when another check is queued. A fetch that
     *                       belongs to no slot is never retried: it ends as {@see SlotRunStatus::Skipped} when the car is not online
     *
     * @throws FleetApiError           when Tesla fails (the message then goes to the failure transport)
     * @throws ORMException
     * @throws ExceptionInterface
     * @throws LockAcquiringException
     * @throws LockConflictedException
     * @throws LockReleasingException
     */
    public function fetch(FetchOdometerMessage $message): SlotRunStatus
    {
        $vehicle = $this->entityManager->find(Vehicle::class, Ulid::fromString($message->vehicleId));

        if (!$vehicle instanceof Vehicle) {
            $this->logger->warning('Odometer fetch dropped: the vehicle does not exist any more.', ['vehicle' => $message->vehicleId]);

            return SlotRunStatus::Skipped;
        }

        $attempt = null === $message->refreshId ? null : $this->attempts->findOf($vehicle, $message->refreshId);
        $lock = $this->lockFactory->createLock('odometer_fetch_' . $vehicle->getId()->toBase32(), self::LOCK_TTL_SECONDS);

        if (!$lock->acquire()) {
            return $this->busy($message, $attempt);
        }

        try {
            return $this->run($message, $vehicle, $attempt);
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws FleetApiError
     * @throws ORMException
     * @throws ExceptionInterface
     */
    private function run(FetchOdometerMessage $message, Vehicle $vehicle, ?RefreshAttempt $attempt): SlotRunStatus
    {
        $now = CarbonImmutable::instance($this->clock->now());
        $run = null === $message->slotTimestamp
            ? null
            : $this->runs->findAt($vehicle, CarbonImmutable::createFromTimestampUTC($message->slotTimestamp));
        $attempts = $message->attempt + 1;

        if (null !== $run && !$run->getStatus()->isOpen()) {
            // A check queued before a newer slot or a successful read: the car is not asked again.
            $this->logger->info('Odometer fetch dropped: the slot is already settled.', [
                'vehicle' => $message->vehicleId,
                'status' => $run->getStatus()->value,
            ]);

            return $run->getStatus();
        }

        $app = $this->teslaApps->find($vehicle->getOwner());

        if (null === $app) {
            return $this->notConnected($run, $attempt, $attempts, $now, $vehicle, 'there is no Tesla key pair');
        }

        try {
            $result = $this->reader->read($app, $vehicle->getVin());
        } catch (TeslaNotConnected $notConnected) {
            return $this->notConnected($run, $attempt, $attempts, $now, $vehicle, $notConnected->getMessage());
        } catch (FleetApiError $failure) {
            $attempt?->settle(RefreshOutcome::Failed, $now);
            $this->entityManager->flush();

            throw $failure;
        }

        if (null === $result->reading) {
            return $this->notOnline($message, $run, $attempt, $result->reason ?? NoReadingReason::Unavailable, $attempts, $now);
        }

        $recording = $this->readings->recordFromApi($vehicle, $result->reading->readAt, $result->reading->odometer);

        if (RecordOutcome::Rejected === $recording->outcome) {
            $this->logger->warning('Odometer read from the car is lower than a stored reading and was not stored.', [
                'vehicle' => $message->vehicleId,
                'read_m' => $result->reading->odometer->metres(),
                'stored_m' => $recording->conflicting?->getOdometer()->metres(),
                'stored_at' => $recording->conflicting?->getReadAt()->toIso8601String(),
                'rule' => $recording->rejection?->name,
            ]);
        }

        $this->ledger->supersedeOpen($vehicle, $now, $run);

        $attempt?->settle(
            match ($recording->outcome) {
                RecordOutcome::Stored => RefreshOutcome::Stored,
                RecordOutcome::Unchanged => RefreshOutcome::Unchanged,
                RecordOutcome::Rejected => RefreshOutcome::Rejected,
            },
            $now,
        );

        return $this->finish(
            $run,
            match ($recording->outcome) {
                RecordOutcome::Stored => SlotRunStatus::Stored,
                RecordOutcome::Unchanged => SlotRunStatus::Unchanged,
                RecordOutcome::Rejected => SlotRunStatus::Rejected,
            },
            $attempts,
            $now,
        );
    }

    /**
     * Another fetch of the vehicle holds the lock.
     *
     * @throws ORMException
     * @throws ExceptionInterface
     */
    private function busy(FetchOdometerMessage $message, ?RefreshAttempt $attempt): SlotRunStatus
    {
        $this->logger->info('Odometer fetch not run: another fetch of the vehicle is running.', ['vehicle' => $message->vehicleId]);

        if (null === $message->slotTimestamp) {
            $attempt?->settle(RefreshOutcome::Busy, CarbonImmutable::instance($this->clock->now()));
            $this->entityManager->flush();

            return SlotRunStatus::Skipped;
        }

        $this->bus->dispatch($message, [new DelayStamp(self::BUSY_DELAY_SECONDS * 1000)]);

        return SlotRunStatus::Retrying;
    }

    /**
     * @throws ORMException
     * @throws ExceptionInterface
     */
    private function notOnline(FetchOdometerMessage $message, ?FetchSlotRun $run, ?RefreshAttempt $attempt, NoReadingReason $reason, int $attempts, CarbonImmutable $now): SlotRunStatus
    {
        if (null === $message->slotTimestamp || FetchWindow::isLastAttempt($message->attempt)) {
            $this->logger->info('Odometer fetch skipped: the car was not online.', [
                'vehicle' => $message->vehicleId,
                'reason' => $reason->value,
                'attempts' => $attempts,
            ]);
            $attempt?->settle(
                match ($reason) {
                    NoReadingReason::Asleep => RefreshOutcome::Asleep,
                    NoReadingReason::Offline => RefreshOutcome::Offline,
                    NoReadingReason::Unavailable => RefreshOutcome::Unavailable,
                },
                $now,
            );

            return $this->finish($run, SlotRunStatus::Skipped, $attempts, $now);
        }

        $this->finish($run, SlotRunStatus::Retrying, $attempts, $now);
        $delay = FetchWindow::retryDelaySeconds(CarbonImmutable::createFromTimestampUTC($message->slotTimestamp), $message->attempt, $now);
        $this->bus->dispatch($message->nextAttempt(), [new DelayStamp($delay * 1000)]);

        return SlotRunStatus::Retrying;
    }

    /**
     * @throws ORMException
     */
    private function notConnected(?FetchSlotRun $run, ?RefreshAttempt $attempt, int $attempts, CarbonImmutable $now, Vehicle $vehicle, string $why): SlotRunStatus
    {
        $attempt?->settle(RefreshOutcome::NotConnected, $now);

        $this->logger->warning('Odometer fetch not run: Polaris is not connected to Tesla.', [
            'vehicle' => $vehicle->getId()->toRfc4122(),
            'why' => $why,
        ]);

        return $this->finish($run, SlotRunStatus::NotConnected, $attempts, $now);
    }

    /**
     * @throws ORMException
     */
    private function finish(?FetchSlotRun $run, SlotRunStatus $status, int $attempts, CarbonImmutable $now): SlotRunStatus
    {
        $run?->record($status, $attempts, $now);
        $this->entityManager->flush();

        return $status;
    }
}
