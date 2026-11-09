<?php

declare(strict_types=1);

namespace Polaris\Lease\Service;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Lease\Event\LeaseReturned;
use Polaris\Lease\Event\LeaseReturnUndone;
use Polaris\Lease\Repository\LeaseContractRepository;
use Polaris\Lease\Service\Enum\LeaseReturnRejection;
use Polaris\Lease\Service\Exception\LeaseReturnRejected;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Whether the user gave the car back to the lessor, and the only way to say so or to undo it.
 *
 * The user gives a calendar date; the car counts as returned from the START of the next day in the owner's time
 * zone, so the readings of the return day still count. Nothing after that instant counts for the lease (see
 * {@see LeaseTermsService::assess()}); readings after it stay stored and count again after an undo.
 */
final readonly class LeaseReturnService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LeaseContractRepository $contracts,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Whether the user gave the car back.
     */
    public function isReturned(Vehicle $vehicle): bool
    {
        return null !== $this->returnedAt($vehicle);
    }

    /**
     * The instant the car counts as returned (the start of the day after the return, UTC), null while the lease
     * runs. Readings up to and including this instant count for the lease; later ones do not.
     */
    public function returnedAt(Vehicle $vehicle): ?CarbonImmutable
    {
        return $this->contracts->findForVehicle($vehicle)?->getReturnedAt();
    }

    /**
     * The calendar date (midnight UTC) the user gave as the day the car went back, or null while the lease runs.
     * It is the day before the stored instant, read in the owner's time zone.
     */
    public function returnedOn(Vehicle $vehicle): ?CarbonImmutable
    {
        $returnedAt = $this->returnedAt($vehicle);

        if (null === $returnedAt) {
            return null;
        }

        $day = $returnedAt->setTimezone($vehicle->getOwner()->getTimeZone())->subDay();
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $day->format('Y-m-d'), 'UTC');
        \assert($date instanceof CarbonImmutable);

        return $date;
    }

    /**
     * Marks the car as returned on a calendar date of the owner's time zone, or moves that date.
     *
     * @throws LeaseReturnRejected when there is no contract, or the date is before the lease start or after today
     * @throws ORMException
     */
    public function markReturned(Vehicle $vehicle, \DateTimeInterface $returnedOn): void
    {
        $contract = $this->contracts->findForVehicle($vehicle);

        if (null === $contract) {
            throw new LeaseReturnRejected(LeaseReturnRejection::NoContract);
        }

        $timeZone = $vehicle->getOwner()->getTimeZone();
        $date = $returnedOn->format('Y-m-d');

        if ($date < $contract->getTerm()->start()->format('Y-m-d')) {
            throw new LeaseReturnRejected(LeaseReturnRejection::BeforeStart);
        }

        if ($date > CarbonImmutable::instance($this->clock->now())->setTimezone($timeZone)->format('Y-m-d')) {
            throw new LeaseReturnRejected(LeaseReturnRejection::InFuture);
        }

        $startOfReturnDay = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timeZone);
        \assert($startOfReturnDay instanceof CarbonImmutable);

        $contract->markReturned($startOfReturnDay->addDay()->setTimezone('UTC'));
        $this->entityManager->flush();
        $this->events->dispatch(new LeaseReturned($vehicle));
    }

    /**
     * The lease is assessed again from the next check; nothing else changes.
     *
     * @throws ORMException
     */
    public function undoReturn(Vehicle $vehicle): void
    {
        $contract = $this->contracts->findForVehicle($vehicle);

        if (null === $contract?->getReturnedAt()) {
            return;
        }

        $contract->undoReturn();
        $this->entityManager->flush();
        $this->events->dispatch(new LeaseReturnUndone($vehicle));
    }
}
