<?php

declare(strict_types=1);

namespace Polaris\Fetching\Service;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Fetching\Domain\FetchWindow;
use Polaris\Fetching\Domain\SlotRunStatus;
use Polaris\Fetching\Entity\FetchSlotRun;
use Polaris\Fetching\Message\FetchOdometerMessage;
use Polaris\Fetching\Repository\FetchSlotRunRepository;
use Polaris\Reading\Service\Enum\RecordOutcome;
use Polaris\Reading\Service\OdometerReadingService;
use Polaris\Tesla\Service\Exception\FleetApiError;
use Polaris\Tesla\Service\Exception\TeslaNotConnected;
use Polaris\Tesla\Service\TeslaAppSettings;
use Polaris\Tesla\Service\TeslaOdometerReader;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Ulid;

/**
 * Runs one {@see FetchOdometerMessage}: asks {@see TeslaOdometerReader} (which never wakes the car) for
 * the odometer, stores it when it changed, and, when the car is not online, checks again an hour later
 * until the window of the slot closes. The checks stay on the hours after the slot, not an hour after the previous one. Messenger's own retry is off for this message: a billed call is
 * never repeated by it, only by the hourly rule here.
 */
final readonly class OdometerFetcher
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FetchSlotRunRepository $runs,
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
     * @throws FleetApiError      when Tesla fails (the message then goes to the failure transport)
     * @throws ORMException
     * @throws ExceptionInterface
     */
    public function fetch(FetchOdometerMessage $message): SlotRunStatus
    {
        $vehicle = $this->entityManager->find(Vehicle::class, Ulid::fromString($message->vehicleId));

        if (!$vehicle instanceof Vehicle) {
            $this->logger->warning('Odometer fetch dropped: the vehicle does not exist any more.', ['vehicle' => $message->vehicleId]);

            return SlotRunStatus::Skipped;
        }

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
            return $this->notConnected($run, $attempts, $now, $vehicle, 'there is no Tesla key pair');
        }

        try {
            $result = $this->reader->read($app, $vehicle->getVin());
        } catch (TeslaNotConnected $notConnected) {
            return $this->notConnected($run, $attempts, $now, $vehicle, $notConnected->getMessage());
        }

        if (null === $result->reading) {
            return $this->notOnline($message, $run, $attempts, $now, $vehicle, $result->reason->value ?? 'unknown');
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
     * @throws ORMException
     * @throws ExceptionInterface
     */
    private function notOnline(FetchOdometerMessage $message, ?FetchSlotRun $run, int $attempts, CarbonImmutable $now, Vehicle $vehicle, string $reason): SlotRunStatus
    {
        if (null === $message->slotTimestamp || FetchWindow::isLastAttempt($message->attempt)) {
            $this->logger->info('Odometer fetch skipped: the car was not online.', [
                'vehicle' => $message->vehicleId,
                'reason' => $reason,
                'attempts' => $attempts,
            ]);

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
    private function notConnected(?FetchSlotRun $run, int $attempts, CarbonImmutable $now, Vehicle $vehicle, string $why): SlotRunStatus
    {
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
