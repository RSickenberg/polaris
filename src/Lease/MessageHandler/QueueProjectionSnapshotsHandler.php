<?php

declare(strict_types=1);

namespace Polaris\Lease\MessageHandler;

use Polaris\Lease\Message\QueueProjectionSnapshots;
use Polaris\Lease\Message\SaveProjectionSnapshot;
use Polaris\Lease\Repository\LeaseContractRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class QueueProjectionSnapshotsHandler
{
    public function __construct(
        private LeaseContractRepository $contracts,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * One message per lease, so a failure in one does not stop the others.
     *
     * @throws ExceptionInterface
     */
    public function __invoke(QueueProjectionSnapshots $message): void
    {
        foreach ($this->contracts->findAll() as $contract) {
            $this->bus->dispatch(new SaveProjectionSnapshot($contract->getVehicle()->getId()->toRfc4122()));
        }
    }
}
