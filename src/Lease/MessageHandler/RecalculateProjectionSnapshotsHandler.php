<?php

declare(strict_types=1);

namespace Polaris\Lease\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Lease\Message\RecalculateProjectionSnapshots;
use Polaris\Lease\Service\ProjectionSnapshotService;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Uid\Ulid;

#[AsMessageHandler]
final readonly class RecalculateProjectionSnapshotsHandler
{
    /** Another save of the lease was running (a midnight snapshot): the message is retried a little later. */
    private const int RETRY_DELAY_MS = 30_000;

    public function __construct(
        private ProjectionSnapshotService $snapshots,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws ORMException
     * @throws LockAcquiringException
     * @throws LockConflictedException
     * @throws LockReleasingException
     * @throws RecoverableMessageHandlingException when another save of the lease holds the lock: Messenger retries
     */
    public function __invoke(RecalculateProjectionSnapshots $message): void
    {
        $vehicle = $this->entityManager->find(Vehicle::class, Ulid::fromString($message->vehicleId));

        if (!$vehicle instanceof Vehicle) {
            $this->logger->warning(
                'Projection snapshots not recalculated: the vehicle does not exist any more.',
                ['vehicle' => $message->vehicleId],
            );

            return;
        }

        $result = $this->snapshots->recalculate($vehicle);

        if ($result->busy) {
            $this->logger->warning(
                'Projection snapshots not recalculated: another save of the lease is running.',
                ['vehicle' => $message->vehicleId],
            );

            throw new RecoverableMessageHandlingException(
                'Another save of the lease is running.',
                retryDelay: self::RETRY_DELAY_MS,
            );
        }

        $this->logger->info(
            'Projection snapshots recalculated.',
            [
                'vehicle' => $message->vehicleId,
                'recomputed' => $result->recomputed,
                'deleted' => $result->deleted,
                'unchanged' => $result->unchanged,
            ],
        );
    }
}
