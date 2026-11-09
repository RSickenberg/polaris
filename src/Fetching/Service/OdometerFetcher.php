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
use Polaris\Lease\Service\LeaseReturnService;
use Polaris\Reading\Service\Enum\RecordOutcome;
use Polaris\Reading\Service\OdometerReadingService;
use Polaris\Reading\Service\VehicleDataRecorder;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Service\Dto\OdometerReading;
use Polaris\Tesla\Service\Dto\OdometerReadResult;
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
 * Runs one {@see FetchOdometerMessage}: asks {@see TeslaOdometerReader} (which never wakes the car) for the
 * odometer and stores it when it changed.
 *
 * Words used below:
 * - a check is one look at the car for a scheduled slot. When the car is not online, the next check is queued on
 *   the next hour after the slot (not an hour after the previous check) until the window of the slot closes.
 *   {@see FetchOdometerMessage::$attempt} is the index of the check (0 for the first); "checks made" counts them.
 * - a refresh is a manual refresh ({@see RefreshAttempt}): a message with a refresh id settles it.
 *
 * Messenger's own retry is off for this message: a billed call is never repeated by it, only by the hourly rule
 * here. The manual hourly limit ({@see ManualRefreshLimit}) is spent here, right before the billed live read of
 * an online car: the state check comes first and is free, so a car found asleep costs nothing against the limit.
 *
 * One fetch runs per vehicle at a time, scheduled or manual, under a lock. A refresh that finds it held ends as
 * {@see RefreshOutcome::Busy}; a scheduled check is queued again shortly, without using up a check.
 *
 * A scheduled fetch wakes the car only at the last check of its window, if the owner allowed it
 * ({@see TeslaApp::wakesOnLastCheck()}), on the same rules as below. A refresh the user confirmed ({@see FetchOdometerMessage::$allowWake})
 * wakes a car found asleep, once and within the daily cap: wake_up is sent, then the same message is queued again
 * every 10 seconds, with the deadline in it, to look at the list of vehicles until the car is online (and then
 * read it as usual) or 90 seconds have passed. No worker waits: the lock is held for one step at a time, and a
 * wake in progress (the refresh is {@see RefreshOutcome::Waking}) makes any other fetch of the vehicle back off.
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
        private FetchSlotRunRepository $slotRuns,
        private RefreshAttemptRepository $refreshes,
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
        private LeaseReturnService $leaseReturns,
    ) {
    }

    /**
     * @return SlotRunStatus how the fetch ended, or {@see SlotRunStatus::Retrying} when another check is queued. A
     *                       fetch that belongs to no slot is never retried: it ends as {@see SlotRunStatus::Skipped}
     *                       when the car is not online
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
            $this->logger->warning(
                'Odometer fetch dropped: the vehicle does not exist anymore.',
                ['vehicle' => $message->vehicleId],
            );

            return SlotRunStatus::Skipped;
        }

        $refresh = null === $message->refreshId ? null : $this->refreshes->findOf($vehicle, $message->refreshId);
        $lock = $this->lockFactory->createLock(
            'odometer_fetch_' . $vehicle->getId()->toBase32(),
            self::LOCK_TTL_SECONDS,
        );

        if (!$lock->acquire()) {
            return $this->busy($message, $refresh);
        }

        try {
            $pendingSince = WakeWindow::pendingSince(CarbonImmutable::instance($this->clock->now()));

            if (!$message->isPollingWake() && $this->refreshes->hasPendingWake($vehicle, $pendingSince)) {
                return $this->busy($message, $refresh);
            }

            return $this->run($message, $vehicle, $refresh);
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws FleetApiError
     * @throws ORMException
     * @throws ExceptionInterface
     */
    private function run(FetchOdometerMessage $message, Vehicle $vehicle, ?RefreshAttempt $refresh): SlotRunStatus
    {
        $now = CarbonImmutable::instance($this->clock->now());
        $run = $this->slotRunOf($message, $vehicle);
        $checksMade = $message->attempt + 1;

        if (null !== $run && !$run->getStatus()->isOpen()) {
            // A check queued before a newer slot or a successful read: the car is not asked again.
            $this->logger->info('Odometer fetch dropped: the slot is already settled.', [
                'vehicle' => $message->vehicleId,
                'status' => $run->getStatus()->value,
            ]);

            return $run->getStatus();
        }

        // The message may have been queued before the user gave the car back: nothing is asked of Tesla, not even
        // the state.
        if ($this->leaseReturns->isReturned($vehicle)) {
            $this->logger->info('Odometer fetch dropped: the lease is returned.', ['vehicle' => $message->vehicleId]);
            $refresh?->settle(RefreshOutcome::Returned, $now);

            return $this->finish($run, SlotRunStatus::Skipped, $checksMade, $now);
        }

        $app = $this->teslaApps->find($vehicle->getOwner());

        if (null === $app) {
            return $this->notConnected($run, $refresh, $checksMade, $now, $vehicle, 'there is no Tesla key pair');
        }

        if ($message->isPollingWake()) {
            $polled = $this->pollWake($message, $vehicle, $app, $run, $refresh, $now);

            if (null !== $polled) {
                return $polled;
            }
        }

        try {
            $result = $this->readCar($message, $vehicle, $app);
        } catch (TeslaNotConnected $notConnected) {
            return $this->notConnected($run, $refresh, $checksMade, $now, $vehicle, $notConnected->getMessage());
        } catch (FleetApiError $failure) {
            $refresh?->settle(RefreshOutcome::Failed, $now);
            $this->entityManager->flush();

            throw $failure;
        }

        if (null === $result) {
            return $this->limited($message, $refresh, $now);
        }

        if (null === $result->reading) {
            $reason = $result->reason ?? NoReadingReason::Unavailable;

            return $this->noReading($message, $vehicle, $app, $run, $refresh, $reason, $checksMade, $now);
        }

        return $this->storeReading($message, $vehicle, $app, $run, $refresh, $result->reading, $checksMade, $now);
    }

    private function slotRunOf(FetchOdometerMessage $message, Vehicle $vehicle): ?FetchSlotRun
    {
        if (null === $message->slotTimestamp) {
            return null;
        }

        return $this->slotRuns->findAt($vehicle, CarbonImmutable::createFromTimestampUTC($message->slotTimestamp));
    }

    /**
     * Asks the state, which is free, then reads the odometer when the car is online. After a wake the list of
     * vehicles already said the car is online, so its state is not asked again.
     *
     * @return OdometerReadResult|null null when a refresh found the hourly limit spent: the car was not read
     *
     * @throws TeslaNotConnected
     * @throws FleetApiError
     */
    private function readCar(FetchOdometerMessage $message, Vehicle $vehicle, TeslaApp $app): ?OdometerReadResult
    {
        $state = $message->isPollingWake() ? VehicleState::Online : $this->reader->state($app, $vehicle->getVin());

        if (VehicleState::Online !== $state) {
            return $this->reader->noReading($state);
        }

        // The hour is spent only when the car is really read. A scheduled read is not a manual refresh and is not
        // held by its hourly limit.
        if (null === $message->slotTimestamp && null !== $this->limit->consume($vehicle)) {
            return null;
        }

        return $this->reader->readOnline($app, $vehicle->getVin());
    }

    /**
     * The car gave no odometer: a confirmed refresh wakes it, anything else ends or checks again later.
     *
     * @throws FleetApiError
     * @throws ORMException
     * @throws ExceptionInterface
     */
    private function noReading(
        FetchOdometerMessage $message,
        Vehicle $vehicle,
        TeslaApp $app,
        ?FetchSlotRun $run,
        ?RefreshAttempt $refresh,
        NoReadingReason $reason,
        int $checksMade,
        CarbonImmutable $now,
    ): SlotRunStatus {
        // An offline car is offered the wake too: whether Tesla can wake it is not documented, so what happens is
        // recorded on the refresh (see RefreshAttempt::$wokeFrom).
        if (null !== $refresh && $message->asksForWake() && $reason->isWakeable()) {
            return $this->wake($message, $vehicle, $app, $run, $refresh, $now, $reason);
        }

        // The last check of a scheduled slot may wake the car if the owner allowed it. The wake is recorded on a
        // refresh of its own, which is what the daily cap counts and the poll settles.
        if ($this->wakesAtLastCheck($message, $app, $reason)) {
            $scheduledWake = new RefreshAttempt($vehicle, $now);
            $this->entityManager->persist($scheduledWake);

            return $this->wake($message, $vehicle, $app, $run, $scheduledWake, $now, $reason);
        }

        return $this->notOnline($message, $run, $refresh, $reason, $checksMade, $now);
    }

    private function wakesAtLastCheck(FetchOdometerMessage $message, TeslaApp $app, NoReadingReason $reason): bool
    {
        return null !== $message->slotTimestamp
            && !$message->isPollingWake()
            && $app->wakesOnLastCheck()
            && $reason->isWakeable()
            && FetchWindow::isLastAttempt($message->attempt);
    }

    /**
     * @throws ORMException
     */
    private function storeReading(
        FetchOdometerMessage $message,
        Vehicle $vehicle,
        TeslaApp $app,
        ?FetchSlotRun $run,
        ?RefreshAttempt $refresh,
        OdometerReading $reading,
        int $checksMade,
        CarbonImmutable $now,
    ): SlotRunStatus {
        // What the car answered is kept, if the user allowed it, whatever happens to the odometer value (stored,
        // unchanged or rejected).
        if ($app->keepsVehicleData()) {
            $this->snapshots->record($vehicle, $reading->readAt, $now, $reading->payload);
        }

        $recording = $this->readings->recordFromApi($vehicle, $reading->readAt, $reading->odometer);

        if (RecordOutcome::Rejected === $recording->outcome) {
            $this->logger->warning('Odometer read from the car is lower than a stored reading and was not stored.', [
                'vehicle' => $message->vehicleId,
                'read_m' => $reading->odometer->metres(),
                'stored_m' => $recording->conflicting?->getOdometer()->metres(),
                'stored_at' => $recording->conflicting?->getReadAt()->toIso8601String(),
                'rule' => $recording->rejection?->name,
            ]);
        }

        $this->ledger->supersedeOpen($vehicle, $now, $run);
        $refresh?->settle(self::refreshOutcomeOf($recording->outcome), $now);
        $status = $this->finish($run, self::slotStatusOf($recording->outcome), $checksMade, $now);

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
    private function wake(
        FetchOdometerMessage $message,
        Vehicle $vehicle,
        TeslaApp $app,
        ?FetchSlotRun $run,
        RefreshAttempt $refresh,
        CarbonImmutable $now,
        NoReadingReason $from,
    ): SlotRunStatus {
        $checksMade = $message->attempt + 1;
        // A scheduled check is not held by the hourly limit of the manual refresh.
        $limited = null === $message->slotTimestamp && null !== $this->limit->retryAfter($vehicle);

        if (WakeAccess::ScopeMissing === $this->waker->access($app)) {
            $this->logger->info(
                'Wake not sent: the connection lacks the vehicle_cmds scope.',
                ['vehicle' => $message->vehicleId],
            );

            return $this->stop($refresh, RefreshOutcome::WakeScopeMissing, $now, $run, $checksMade);
        }

        if ($limited) {
            $this->logger->info(
                'Wake not sent: the hourly limit of the refresh is spent.',
                ['vehicle' => $message->vehicleId],
            );

            return $this->stop($refresh, RefreshOutcome::Limited, $now, $run, $checksMade);
        }

        $cap = $this->wakeCap->status($vehicle, $app, $now);

        if ($cap->isReached()) {
            $this->logger->info(
                'Wake not sent: the daily cap is reached.',
                ['vehicle' => $message->vehicleId, 'cap' => $cap->cap],
            );

            return $this->stop($refresh, RefreshOutcome::WakeCapReached, $now, $run, $checksMade);
        }

        try {
            $this->waker->wake($app, $vehicle->getVin());
        } catch (TeslaNotConnected $notConnected) {
            return $this->notConnected($run, $refresh, $checksMade, $now, $vehicle, $notConnected->getMessage());
        } catch (WakeScopeMissing) {
            $this->logger->warning(
                'Wake refused by Tesla: the vehicle_cmds scope is missing or was revoked.',
                ['vehicle' => $message->vehicleId],
            );

            return $this->stop($refresh, RefreshOutcome::WakeScopeMissing, $now, $run, $checksMade);
        } catch (FleetApiError $failure) {
            if ($failure->isVehicleNotOnline()) {
                $this->logger->info('Wake not delivered: Tesla could not reach the car.', [
                    'vehicle' => $message->vehicleId,
                    'state_before' => $from->value,
                ]);

                return $this->stop($refresh, RefreshOutcome::WakeFailed, $now, $run, $checksMade);
            }

            $refresh->settle(RefreshOutcome::Failed, $now);
            $this->entityManager->flush();

            throw $failure;
        }

        $refresh->markWoken($now, $from);
        $this->entityManager->flush();

        $this->logger->info('Wake sent.', [
            'vehicle' => $message->vehicleId,
            'state_before' => $from->value,
            'woken_today' => $cap->used + 1,
        ]);

        // The slot stays open while the poll runs, so the dispatcher does not queue it again.
        $this->finish($run, SlotRunStatus::Retrying, $checksMade, $now);

        $this->bus->dispatch(
            $message->wokenUntil(WakeWindow::deadline($now)->getTimestamp(), $refresh->getId()->toRfc4122()),
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
    private function pollWake(
        FetchOdometerMessage $message,
        Vehicle $vehicle,
        TeslaApp $app,
        ?FetchSlotRun $run,
        ?RefreshAttempt $refresh,
        CarbonImmutable $now,
    ): ?SlotRunStatus {
        $checksMade = $message->attempt + 1;

        if (null === $refresh || RefreshOutcome::Waking !== $refresh->getOutcome()) {
            // The refresh was purged or settled by someone else: nothing to wait for.
            return $this->finish($run, SlotRunStatus::Skipped, $checksMade, $now);
        }

        try {
            if ($this->waker->isOnline($app, $vehicle->getVin())) {
                $this->logger->info('The car came online after the wake.', [
                    'vehicle' => $message->vehicleId,
                    'state_before' => $refresh->getWokeFrom()?->value,
                    'seconds_after_wake' => $this->secondsSinceWake($refresh, $now),
                ]);

                return null;
            }
        } catch (TeslaNotConnected $notConnected) {
            return $this->notConnected($run, $refresh, $checksMade, $now, $vehicle, $notConnected->getMessage());
        } catch (FleetApiError $failure) {
            $refresh->settle(RefreshOutcome::Failed, $now);
            $this->entityManager->flush();

            throw $failure;
        }

        if (WakeWindow::isOver((int) $message->wakeDeadline, $now)) {
            $this->logger->info('The car did not come online after the wake.', [
                'vehicle' => $message->vehicleId,
                'state_before' => $refresh->getWokeFrom()?->value,
                'seconds_after_wake' => $this->secondsSinceWake($refresh, $now),
            ]);

            return $this->stop($refresh, RefreshOutcome::WakeTimeout, $now, $run, $checksMade);
        }

        $this->bus->dispatch($message, [new DelayStamp(WakeWindow::POLL_INTERVAL_SECONDS * 1000)]);

        return SlotRunStatus::Retrying;
    }

    private function secondsSinceWake(RefreshAttempt $refresh, CarbonImmutable $now): ?int
    {
        $wokeAt = $refresh->getWokeAt();

        return null === $wokeAt ? null : $now->getTimestamp() - $wokeAt->getTimestamp();
    }

    /**
     * @throws ORMException
     */
    private function stop(
        ?RefreshAttempt $refresh,
        RefreshOutcome $outcome,
        CarbonImmutable $now,
        ?FetchSlotRun $run = null,
        int $checksMade = 0,
    ): SlotRunStatus {
        $refresh?->settle($outcome, $now);

        return $this->finish($run, SlotRunStatus::Skipped, $checksMade, $now);
    }

    /**
     * The car is online but another manual refresh spent the hour first.
     *
     * @throws ORMException
     */
    private function limited(
        FetchOdometerMessage $message,
        ?RefreshAttempt $refresh,
        CarbonImmutable $now,
    ): SlotRunStatus {
        $this->logger->info(
            'Odometer fetch not run: the hourly limit of the manual refresh is spent.',
            ['vehicle' => $message->vehicleId],
        );

        return $this->stop($refresh, RefreshOutcome::Limited, $now);
    }

    /**
     * Another fetch of the vehicle holds the lock.
     *
     * @throws ORMException
     * @throws ExceptionInterface
     */
    private function busy(FetchOdometerMessage $message, ?RefreshAttempt $refresh): SlotRunStatus
    {
        $this->logger->info(
            'Odometer fetch not run: another fetch of the vehicle is running.',
            ['vehicle' => $message->vehicleId],
        );

        if (null === $message->slotTimestamp) {
            $refresh?->settle(RefreshOutcome::Busy, CarbonImmutable::instance($this->clock->now()));
            $this->entityManager->flush();

            return SlotRunStatus::Skipped;
        }

        $this->bus->dispatch($message, [new DelayStamp(self::BUSY_DELAY_SECONDS * 1000)]);

        return SlotRunStatus::Retrying;
    }

    /**
     * The car is not online: a refresh or the last check of a slot ends here, an earlier check is queued again.
     *
     * @throws ORMException
     * @throws ExceptionInterface
     */
    private function notOnline(
        FetchOdometerMessage $message,
        ?FetchSlotRun $run,
        ?RefreshAttempt $refresh,
        NoReadingReason $reason,
        int $checksMade,
        CarbonImmutable $now,
    ): SlotRunStatus {
        if (null === $message->slotTimestamp || FetchWindow::isLastAttempt($message->attempt)) {
            $this->logger->info('Odometer fetch skipped: the car was not online.', [
                'vehicle' => $message->vehicleId,
                'reason' => $reason->value,
                'attempts' => $checksMade,
            ]);
            $refresh?->settle(self::refreshOutcomeOfReason($reason), $now);

            return $this->finish($run, SlotRunStatus::Skipped, $checksMade, $now);
        }

        $this->finish($run, SlotRunStatus::Retrying, $checksMade, $now);
        $delay = FetchWindow::retryDelaySeconds(
            CarbonImmutable::createFromTimestampUTC($message->slotTimestamp),
            $message->attempt,
            $now,
        );
        $this->bus->dispatch($message->nextAttempt(), [new DelayStamp($delay * 1000)]);

        return SlotRunStatus::Retrying;
    }

    /**
     * @throws ORMException
     */
    private function notConnected(
        ?FetchSlotRun $run,
        ?RefreshAttempt $refresh,
        int $checksMade,
        CarbonImmutable $now,
        Vehicle $vehicle,
        string $why,
    ): SlotRunStatus {
        $refresh?->settle(RefreshOutcome::NotConnected, $now);

        $this->logger->warning('Odometer fetch not run: Polaris is not connected to Tesla.', [
            'vehicle' => $vehicle->getId()->toRfc4122(),
            'why' => $why,
        ]);

        return $this->finish($run, SlotRunStatus::NotConnected, $checksMade, $now);
    }

    /**
     * @throws ORMException
     */
    private function finish(
        ?FetchSlotRun $run,
        SlotRunStatus $status,
        int $checksMade,
        CarbonImmutable $now,
    ): SlotRunStatus {
        $run?->record($status, $checksMade, $now);
        $this->entityManager->flush();

        return $status;
    }

    private static function refreshOutcomeOf(RecordOutcome $recorded): RefreshOutcome
    {
        return match ($recorded) {
            RecordOutcome::Stored => RefreshOutcome::Stored,
            RecordOutcome::Unchanged => RefreshOutcome::Unchanged,
            RecordOutcome::Rejected => RefreshOutcome::Rejected,
        };
    }

    private static function slotStatusOf(RecordOutcome $recorded): SlotRunStatus
    {
        return match ($recorded) {
            RecordOutcome::Stored => SlotRunStatus::Stored,
            RecordOutcome::Unchanged => SlotRunStatus::Unchanged,
            RecordOutcome::Rejected => SlotRunStatus::Rejected,
        };
    }

    private static function refreshOutcomeOfReason(NoReadingReason $reason): RefreshOutcome
    {
        return match ($reason) {
            NoReadingReason::Asleep => RefreshOutcome::Asleep,
            NoReadingReason::Offline => RefreshOutcome::Offline,
            NoReadingReason::Unavailable => RefreshOutcome::Unavailable,
        };
    }
}
