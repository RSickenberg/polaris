<?php

declare(strict_types=1);

namespace Polaris\Lease\MessageHandler;

use Polaris\Account\Message\OwnerMidnightPassed;
use Polaris\Lease\Message\SaveProjectionSnapshot;
use Polaris\Lease\Repository\LeaseContractRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Ulid;

/**
 * The Lease share of the owner's midnight: one {@see SaveProjectionSnapshot} per lease of the owner, so a failure in
 * one does not stop the others.
 */
#[AsMessageHandler]
final readonly class QueueOwnerProjectionSnapshotsHandler
{
    public function __construct(
        private LeaseContractRepository $contracts,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws ExceptionInterface
     */
    public function __invoke(OwnerMidnightPassed $message): void
    {
        $contracts = $this->contracts->findOfOwner(Ulid::fromString($message->userId));
        $this->logger->info(
            'Owner midnight: queuing projection snapshots.',
            ['user' => $message->userId, 'local_day' => $message->localDay, 'leases' => \count($contracts)],
        );

        foreach ($contracts as $contract) {
            $this->bus->dispatch(
                new SaveProjectionSnapshot(
                    $contract->getVehicle()->getId()->toRfc4122(),
                    $message->localDay,
                    $message->midnightTimestamp,
                )
            );
        }
    }
}
