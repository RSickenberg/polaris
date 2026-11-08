<?php

declare(strict_types=1);

namespace Polaris\Alert\EventListener;

use Polaris\Alert\Message\CheckRiskLevel;
use Polaris\Lease\Event\LeaseReturnUndone;
use Polaris\Lease\Event\LeaseTermsSaved;
use Polaris\Reading\Event\ReadingStored;
use Polaris\Reading\Event\ReadingVerified;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Whatever can move the projection of a vehicle queues a check of its risk level. Nothing is assessed here: the
 * check runs on the worker, so a slow alert never holds up a reading or a fetch.
 */
final readonly class QueueRiskLevelCheck
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    /**
     * @throws ExceptionInterface
     */
    #[AsEventListener]
    public function onReadingStored(ReadingStored $event): void
    {
        $this->queue($event->vehicle);
    }

    /**
     * @throws ExceptionInterface
     */
    #[AsEventListener]
    public function onReadingVerified(ReadingVerified $event): void
    {
        $this->queue($event->vehicle);
    }

    /**
     * @throws ExceptionInterface
     */
    #[AsEventListener]
    public function onLeaseTermsSaved(LeaseTermsSaved $event): void
    {
        $this->queue($event->vehicle);
    }

    /**
     * A returned lease is not assessed, so nothing is queued when it is marked returned; after an undo the lease is
     * assessed again from the next check.
     *
     * @throws ExceptionInterface
     */
    #[AsEventListener]
    public function onLeaseReturnUndone(LeaseReturnUndone $event): void
    {
        $this->queue($event->vehicle);
    }

    /**
     * @throws ExceptionInterface
     */
    private function queue(Vehicle $vehicle): void
    {
        $this->bus->dispatch(new CheckRiskLevel($vehicle->getId()->toRfc4122()));
    }
}
