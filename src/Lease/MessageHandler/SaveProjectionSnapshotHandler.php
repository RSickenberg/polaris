<?php

declare(strict_types=1);

namespace Polaris\Lease\MessageHandler;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Lease\Message\SaveProjectionSnapshot;
use Polaris\Lease\Service\Enum\SnapshotOutcome;
use Polaris\Lease\Service\ProjectionSnapshotService;
use Polaris\Shared\Domain\OwnerMidnight;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Ulid;

#[AsMessageHandler]
final readonly class SaveProjectionSnapshotHandler
{
    /** Another save of the lease was running: it is queued once more, a little later. */
    private const int RETRY_DELAY_MS = 30_000;
    private const int MAX_RETRIES = 1;

    public function __construct(
        private ProjectionSnapshotService $snapshots,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws ORMException
     * @throws ExceptionInterface
     * @throws LockAcquiringException
     * @throws LockConflictedException
     * @throws LockReleasingException
     */
    public function __invoke(SaveProjectionSnapshot $message): void
    {
        $vehicle = $this->entityManager->find(Vehicle::class, Ulid::fromString($message->vehicleId));

        if (!$vehicle instanceof Vehicle) {
            $this->logger->warning(
                'Projection snapshot dropped: the vehicle does not exist any more.',
                ['vehicle' => $message->vehicleId, 'local_day' => $message->localDay],
            );

            return;
        }

        $localDay = CarbonImmutable::createFromFormat('!Y-m-d', $message->localDay, 'UTC');

        if (!$localDay instanceof CarbonImmutable) {
            return;
        }

        $midnight = OwnerMidnight::of(
            CarbonImmutable::createFromTimestamp($message->midnightTimestamp, 'UTC'),
            $localDay,
        );

        $outcome = $this->snapshots->save($vehicle, $midnight);
        $this->logger->log(
            $outcome->isStored() ? LogLevel::INFO : LogLevel::WARNING,
            'Projection snapshot: ' . $outcome->value . '.',
            [
                'vehicle' => $message->vehicleId,
                'local_day' => $message->localDay,
                'attempt' => $message->attempt,
            ],
        );

        if (SnapshotOutcome::Busy === $outcome && $message->attempt < self::MAX_RETRIES) {
            $this->bus->dispatch(
                new SaveProjectionSnapshot(
                    $message->vehicleId,
                    $message->localDay,
                    $message->midnightTimestamp,
                    $message->attempt + 1,
                ),
                [new DelayStamp(self::RETRY_DELAY_MS)],
            );
        }
    }
}
