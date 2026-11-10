<?php

declare(strict_types=1);

namespace Polaris\Lease\EventListener;

use Polaris\Lease\Event\LeaseTermsSaved;
use Polaris\Lease\Message\RecalculateProjectionSnapshots;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Terms that changed what a projection depends on queue the recalculation of the snapshots; the request that saved
 * them does not wait for it. A save that changed nothing of the sort queues nothing.
 */
final readonly class QueueProjectionRecalculation
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    /**
     * @throws ExceptionInterface
     */
    #[AsEventListener]
    public function onLeaseTermsSaved(LeaseTermsSaved $event): void
    {
        if (!$event->projectionChanged) {
            return;
        }

        $this->bus->dispatch(new RecalculateProjectionSnapshots($event->vehicle->getId()->toRfc4122()));
    }
}
