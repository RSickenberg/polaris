<?php

declare(strict_types=1);

namespace Polaris\Fetching\Service;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Fetching\Domain\FetchWindow;
use Polaris\Fetching\Domain\RefreshOutcome;
use Polaris\Fetching\Domain\SlotRunStatus;
use Polaris\Fetching\Domain\WakeWindow;
use Polaris\Fetching\Entity\FetchSlotRun;
use Polaris\Fetching\Entity\RefreshAttempt;
use Polaris\Fetching\Message\FetchOdometerMessage;
use Polaris\Fetching\Repository\FetchSlotRunRepository;
use Polaris\Fetching\Repository\RefreshAttemptRepository;
use Polaris\Reading\Service\Enum\RecordOutcome;
use Polaris\Reading\Service\OdometerReadingService;
use Polaris\Reading\Service\VehicleDataRecorder;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Service\Enum\NoReadingReason;
use Polaris\Tesla\Service\Enum\VehicleState;
use Polaris\Tesla\Service\Enum\WakeAccess;
use Polaris\Tesla\Service\Exception\FleetApiError;
use Polaris\Tesla\Service\Exception\TeslaNotConnected;
use Polaris\Tesla\Service\Exception\WakeScopeMissing;
use Polaris\Tesla\Service\TeslaAppSettings;
use Polaris\Tesla\Service\TeslaOdometerReader;
use Polaris\Tesla\Service\TeslaVehicleWaker;
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
 * The manual hourly limit ({@see ManualRefreshLimit}) is spent here, right before the billed live read of an online
 * car: the state check comes first and is free, so a car found asleep costs nothing against the limit.
 *
 * One fetch runs per vehicle at a time, scheduled or manual, under a lock. A manual refresh that finds it held
 * ends as {@see RefreshOutcome::Busy}; a scheduled check is queued again shortly, without using up a state check.
 * A message with a refresh id settles that {@see RefreshAttempt}.
 *
 * A scheduled fetch never wakes the car. A manual refresh the user confirmed ({@see FetchOdometerMessage::$allowWake})
 * wakes a car found asleep, once and within the daily cap: wake_up is sent, then the same message is queued again
 * every 10 seconds, with the deadline in it, to look at the list of vehicles until the car is online (and then
 * read it as usual) or 90 seconds have passed. No worker waits: the lock is held for one step at a time, and a
 * wake in progress (the attempt is {@see RefreshOutcome::Waking}) makes any other fetch of the vehicle back off.
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
        private TeslaVehicleWaker $waker,
        private ManualRefreshLimit $limit,
        private WakeCap $wakeCap,
        private OdometerReadingService $readings,
        private VehicleDataRecorder $snapshots,
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
            $pendingSince = WakeWindow::pendingSince(CarbonImmutable::instance($this->clock->now()));

            if (!$message->isPollingWake() && $this->attempts->hasPendingWake($vehicle, $pendingSince)) {
                return $this->busy($message, $attempt);
            }

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

        if ($message->isPollingWake()) {
            $polled = $this->pollWake($message, $vehicle, $app, $attempt, $now);

            if (null !== $polled) {
                return $polled;
            }
        }

        try {
            // The check comes first and is free; the hour is spent only when the car is really read. After a wake
            // the list of vehicles already said the car is online, so its state is not asked again.
            $state = $message->isPollingWake() ? VehicleState::Online : $this->reader->state($app, $vehicle->getVin());

            if (VehicleState::Online !== $state) {
                $result = $this->reader->noReading($state);
            } else {
                // A scheduled read is not a manual refresh and is not held by its hourly limit.
                $retryAfter = null === $message->slotTimestamp ? $this->limit->consume($vehicle) : null;

                if (null !== $retryAfter) {
                    return $this->limited($message, $attempt, $now);
                }

                $result = $this->reader->readOnline($app, $vehicle->getVin());
            }
        } catch (TeslaNotConnected $notConnected) {
            return $this->notConnected($run, $attempt, $attempts, $now, $vehicle, $notConnected->getMessage());
        } catch (FleetApiError $failure) {
            $attempt?->settle(RefreshOutcome::Failed, $now);
            $this->entityManager->flush();

            throw $failure;
        }

        if (null === $result->reading) {
            // An offline car is offered the wake too: whether Tesla can wake it is not documented, so what
            // happens is recorded on the attempt (see RefreshAttempt::$wokeFrom).
            $wakeable = [NoReadingReason::Asleep, NoReadingReason::Offline];

            if (null !== $attempt
                && $message->asksForWake()
                && null !== $result->reason
                && \in_array($result->reason, $wakeable, true)
            ) {
                return $this->wake($message, $vehicle, $app, $attempt, $now, $result->reason);
            }

            return $this->notOnline($message, $run, $attempt, $result->reason ?? NoReadingReason::Unavailable, $attempts, $now);
        }

        // What the car answered is kept whatever happens to the odometer value (stored, unchanged or rejected).
        $this->snapshots->record($vehicle, $result->reading->readAt, $now, $result->reading->payload);

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

        $status = $this->finish(
            $run,
            match ($recording->outcome) {
                RecordOutcome::Stored => SlotRunStatus::Stored,
                RecordOutcome::Unchanged => SlotRunStatus::Unchanged,
                RecordOutcome::Rejected => SlotRunStatus::Rejected,
            },
            $attempts,
            $now,
        );

        // Tesla answered, so the worker and the API work: this is the moment to tidy the slots of the vehicle that no
        // window refers to anymore. After the flush of finish(), so no slot is written once it is deleted.
        $this->ledger->purgeOld($vehicle, $now);

        return $status;
    }

    /**
     * Sends wake_up, if the cap and the scope still allow it, and queues the first poll. The wake is counted
     * from the moment Tesla answered it.
     *
     * @throws FleetApiError
     * @throws ORMException
     * @throws ExceptionInterface
     */
    private function wake(FetchOdometerMessage $message, Vehicle $vehicle, TeslaApp $app, RefreshAttempt $attempt, CarbonImmutable $now, NoReadingReason $from): SlotRunStatus
    {
        if (WakeAccess::ScopeMissing === $this->waker->access($app)) {
            $this->logger->info('Wake not sent: the connection lacks the vehicle_cmds scope.', ['vehicle' => $message->vehicleId]);

            return $this->stop($attempt, RefreshOutcome::WakeScopeMissing, $now);
        }

        if (null !== $this->limit->retryAfter($vehicle)) {
            $this->logger->info('Wake not sent: the hourly limit of the refresh is spent.', ['vehicle' => $message->vehicleId]);

            return $this->stop($attempt, RefreshOutcome::Limited, $now);
        }

        $cap = $this->wakeCap->status($vehicle, $app, $now);

        if ($cap->isReached()) {
            $this->logger->info('Wake not sent: the daily cap is reached.', ['vehicle' => $message->vehicleId, 'cap' => $cap->cap]);

            return $this->stop($attempt, RefreshOutcome::WakeCapReached, $now);
        }

        try {
            $this->waker->wake($app, $vehicle->getVin());
        } catch (TeslaNotConnected $notConnected) {
            return $this->notConnected(
                null,
                $attempt,
                $message->attempt + 1,
                $now,
                $vehicle,
                $notConnected->getMessage()
            );
        } catch (WakeScopeMissing) {
            $this->logger->warning(
                'Wake refused by Tesla: the vehicle_cmds scope is missing or was revoked.',
                ['vehicle' => $message->vehicleId]
            );

            return $this->stop($attempt, RefreshOutcome::WakeScopeMissing, $now);
        } catch (FleetApiError $failure) {
            if ($failure->isVehicleNotOnline()) {
                $this->logger->info('Wake not delivered: Tesla could not reach the car.', [
                    'vehicle' => $message->vehicleId,
                    'state_before' => $from->value,
                ]);

                return $this->stop($attempt, RefreshOutcome::WakeFailed, $now);
            }

            $attempt->settle(RefreshOutcome::Failed, $now);
            $this->entityManager->flush();

            throw $failure;
        }

        $attempt->markWoken($now, $from);
        $this->entityManager->flush();

        $this->logger->info('Wake sent.', [
            'vehicle' => $message->vehicleId,
            'state_before' => $from->value,
            'woken_today' => $cap->used + 1,
        ]);

        $this->bus->dispatch(
            $message->wokenUntil(WakeWindow::deadline($now)->getTimestamp()),
            [new DelayStamp(WakeWindow::POLL_INTERVAL_SECONDS * 1000)],
        );

        return SlotRunStatus::Retrying;
    }

    /**
     * One step of the poll after a wake: asks the list of vehicles whether the car is online.
     *
     * @return SlotRunStatus|null null when the car is online and must now be read; otherwise how this step ended
     *
     * @throws FleetApiError
     * @throws ORMException
     * @throws ExceptionInterface
     */
    private function pollWake(FetchOdometerMessage $message, Vehicle $vehicle, TeslaApp $app, ?RefreshAttempt $attempt, CarbonImmutable $now): ?SlotRunStatus
    {
        if (null === $attempt || RefreshOutcome::Waking !== $attempt->getOutcome()) {
            // The refresh was purged or settled by someone else: nothing to wait for.
            return SlotRunStatus::Skipped;
        }

        try {
            if ($this->waker->isOnline($app, $vehicle->getVin())) {
                $this->logger->info('The car came online after the wake.', [
                    'vehicle' => $message->vehicleId,
                    'state_before' => $attempt->getWokeFrom()?->value,
                    'seconds_after_wake' => $this->secondsSinceWake($attempt, $now),
                ]);

                return null;
            }
        } catch (TeslaNotConnected $notConnected) {
            return $this->notConnected(
                null,
                $attempt,
                $message->attempt + 1,
                $now,
                $vehicle,
                $notConnected->getMessage()
            );
        } catch (FleetApiError $failure) {
            $attempt->settle(RefreshOutcome::Failed, $now);
            $this->entityManager->flush();

            throw $failure;
        }

        if (WakeWindow::isOver((int) $message->wakeDeadline, $now)) {
            $this->logger->info('The car did not come online after the wake.', [
                'vehicle' => $message->vehicleId,
                'state_before' => $attempt->getWokeFrom()?->value,
                'seconds_after_wake' => $this->secondsSinceWake($attempt, $now),
            ]);

            return $this->stop($attempt, RefreshOutcome::WakeTimeout, $now);
        }

        $this->bus->dispatch($message, [new DelayStamp(WakeWindow::POLL_INTERVAL_SECONDS * 1000)]);

        return SlotRunStatus::Retrying;
    }

    private function secondsSinceWake(RefreshAttempt $attempt, CarbonImmutable $now): ?int
    {
        $wokeAt = $attempt->getWokeAt();

        return null === $wokeAt ? null : $now->getTimestamp() - $wokeAt->getTimestamp();
    }

    /**
     * @throws ORMException
     */
    private function stop(?RefreshAttempt $attempt, RefreshOutcome $outcome, CarbonImmutable $now): SlotRunStatus
    {
        $attempt?->settle($outcome, $now);
        $this->entityManager->flush();

        return SlotRunStatus::Skipped;
    }

    /**
     * The car is online but another manual refresh spent the hour first.
     *
     * @throws ORMException
     */
    private function limited(FetchOdometerMessage $message, ?RefreshAttempt $attempt, CarbonImmutable $now): SlotRunStatus
    {
        $this->logger->info('Odometer fetch not run: the hourly limit of the manual refresh is spent.', ['vehicle' => $message->vehicleId]);

        return $this->stop($attempt, RefreshOutcome::Limited, $now);
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
