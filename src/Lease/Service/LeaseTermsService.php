<?php

declare(strict_types=1);

namespace Polaris\Lease\Service;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Lease\Domain\LeaseAssessment;
use Polaris\Lease\Domain\LeaseAssessor;
use Polaris\Lease\Domain\NoData;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Lease\Event\LeaseReturned;
use Polaris\Lease\Event\LeaseReturnUndone;
use Polaris\Lease\Event\LeaseTermsSaved;
use Polaris\Lease\Form\Model\LeaseContractData;
use Polaris\Lease\Repository\LeaseContractRepository;
use Polaris\Lease\Service\Dto\LeaseTerms;
use Polaris\Lease\Service\Exception\LeaseReturnRejected;
use Polaris\Lease\Service\Exception\LeaseTermsRejected;
use Polaris\Reading\Service\OdometerReadingService;
use Polaris\Shared\Domain\OdometerSample;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Creates or edits the lease contract of a vehicle and recomputes the projection with the
 * new terms. The only place the rules live: the settings form calls it, and so will the API.
 *
 * The recomputation is synchronous: the projection is a cheap pure calculation, so the
 * caller gets the new assessment right away. Nothing is persisted for it here: the daily
 * snapshot of what it says is {@see ProjectionSnapshotService}, which calls {@see self::assess()}.
 */
final readonly class LeaseTermsService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LeaseContractRepository $contracts,
        private LeaseContractFactory $factory,
        private LeaseAssessor $assessor,
        private OdometerReadingService $readings,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
    ) {
    }

    public function find(Vehicle $vehicle): ?LeaseContract
    {
        return $this->contracts->findForVehicle($vehicle);
    }

    /**
     * Saves the terms, overwriting the existing contract of the vehicle, and returns the
     * projection recomputed with them.
     *
     * @param LeaseContractData $data validated first (see the constraints on the class)
     *
     * @throws \InvalidArgumentException when the data has not been validated first
     * @throws LeaseTermsRejected        when the terms do not fit the recorded readings; nothing is saved
     * @throws ORMException
     */
    public function save(Vehicle $vehicle, LeaseContractData $data): LeaseAssessment|NoData
    {
        $terms = $this->factory->terms($data);

        // Assessed before anything is written, so terms that contradict the readings are refused whole.
        $returnedAt = $this->contracts->findForVehicle($vehicle)?->getReturnedAt();
        $assessment = $this->assessTerms($terms, $this->samples($vehicle, $returnedAt), $returnedAt);

        $contract = $this->contracts->findForVehicle($vehicle);

        // The return is kept by an edit, so a start on or after it would leave no time under the lease.
        if (null !== $contract?->getReturnedAt() && $terms->term->start()->getTimestamp() >= $contract->getReturnedAt()->getTimestamp()) {
            throw new LeaseTermsRejected(LeaseTermsRejected::START_AFTER_RETURN);
        }

        if (null === $contract) {
            $contract = new LeaseContract(
                $vehicle,
                $terms->term,
                $terms->allowance,
                $terms->startOdometer,
                $terms->excessCostPerKm,
                $terms->tolerance
            );
            $this->entityManager->persist($contract);
        } else {
            $contract->updateTerms(
                $terms->term,
                $terms->allowance,
                $terms->startOdometer,
                $terms->excessCostPerKm,
                $terms->tolerance
            );
        }

        $this->entityManager->flush();
        $this->events->dispatch(new LeaseTermsSaved($vehicle));

        return $assessment;
    }

    /**
     * Brings back the lease of an export, only when the vehicle has none: an existing contract is never replaced.
     *
     * @return bool whether the contract was created (false: the vehicle already had one)
     *
     * @throws LeaseTermsRejected when the return is not after the lease start
     * @throws ORMException
     */
    public function import(Vehicle $vehicle, LeaseTerms $terms, ?CarbonImmutable $returnedAt): bool
    {
        if (null !== $this->contracts->findForVehicle($vehicle)) {
            return false;
        }

        if (null !== $returnedAt && $returnedAt->getTimestamp() <= $terms->term->start()->getTimestamp()) {
            throw new LeaseTermsRejected(LeaseTermsRejected::START_AFTER_RETURN);
        }

        $contract = new LeaseContract($vehicle, $terms->term, $terms->allowance, $terms->startOdometer, $terms->excessCostPerKm, $terms->tolerance);

        if (null !== $returnedAt) {
            $contract->markReturned($returnedAt->utc());
        }

        $this->entityManager->persist($contract);
        $this->entityManager->flush();
        $this->events->dispatch(new LeaseTermsSaved($vehicle));

        return true;
    }

    /**
     * The projection of the stored contract with the recorded readings; null without a contract.
     */
    public function assess(Vehicle $vehicle): LeaseAssessment|NoData|null
    {
        $contract = $this->contracts->findForVehicle($vehicle);

        if (null === $contract) {
            return null;
        }

        return $this->assessTerms(
            new LeaseTerms(
                $contract->getTerm(),
                $contract->getAllowance(),
                $contract->getStartOdometer(),
                $contract->getExcessCostPerKm(),
                $contract->getTolerance()
            ),
            $this->samples($vehicle, $contract->getReturnedAt()),
            $contract->getReturnedAt()
        );
    }

    /**
     * The projection as it stood at $cutoff (an owner's midnight): only readings and confirmations up to it count, a
     * confirmation running past it is cut there, and the lease is judged (ended or not, time left) at that instant.
     * A car returned at or before $cutoff is a returned lease, as in {@see self::assess()}; a return after it is
     * ignored, as the car was still leased then. Null without a contract.
     *
     * @throws LeaseTermsRejected when the readings do not fit the terms
     */
    public function assessAt(Vehicle $vehicle, CarbonImmutable $cutoff): LeaseAssessment|NoData|null
    {
        $contract = $this->contracts->findForVehicle($vehicle);

        if (null === $contract) {
            return null;
        }

        $returnedAt = $contract->getReturnedAt();
        $returned = null !== $returnedAt && $returnedAt->lessThanOrEqualTo($cutoff);

        return $this->assessTerms(
            new LeaseTerms(
                $contract->getTerm(),
                $contract->getAllowance(),
                $contract->getStartOdometer(),
                $contract->getExcessCostPerKm(),
                $contract->getTolerance()
            ),
            $this->samples($vehicle, $returned ? $returnedAt : $cutoff),
            $returned ? $returnedAt : null,
            $cutoff
        );
    }

    /**
     * Whether the user gave the car back. Cheap: no assessment is made.
     */
    public function isReturned(Vehicle $vehicle): bool
    {
        return null !== $this->contracts->findForVehicle($vehicle)?->getReturnedAt();
    }

    /**
     * The instant the car counts as returned from (inclusive: nothing after it counts), null while the lease runs.
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
        $returnedAt = $this->contracts->findForVehicle($vehicle)?->getReturnedAt();

        if (null === $returnedAt) {
            return null;
        }

        $day = $returnedAt->setTimezone($vehicle->getOwner()->getTimeZone())->subDay();
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $day->format('Y-m-d'), 'UTC');
        \assert($date instanceof CarbonImmutable);

        return $date;
    }

    /**
     * Marks the car as returned on a calendar date of the owner's time zone, or moves that date. The car counts as
     * returned from the end of that day: readings of the day itself still count. Readings after it stay stored and
     * come back if the return is undone.
     *
     * @throws LeaseReturnRejected when there is no contract, or the date is before the lease start or after today
     * @throws ORMException
     */
    public function markReturned(Vehicle $vehicle, \DateTimeInterface $returnedOn): void
    {
        $contract = $this->contracts->findForVehicle($vehicle);

        if (null === $contract) {
            throw new LeaseReturnRejected(LeaseReturnRejected::NO_CONTRACT);
        }

        $timeZone = $vehicle->getOwner()->getTimeZone();
        $date = $returnedOn->format('Y-m-d');

        if ($date < $contract->getTerm()->start()->format('Y-m-d')) {
            throw new LeaseReturnRejected(LeaseReturnRejected::BEFORE_START);
        }

        if ($date > CarbonImmutable::instance($this->clock->now())->setTimezone($timeZone)->format('Y-m-d')) {
            throw new LeaseReturnRejected(LeaseReturnRejected::IN_FUTURE);
        }

        $endOfDay = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timeZone);
        \assert($endOfDay instanceof CarbonImmutable);

        $contract->markReturned($endOfDay->addDay()->setTimezone('UTC'));
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

    /**
     * @param list<OdometerSample> $samples
     */
    private function assessTerms(LeaseTerms $terms, array $samples, ?CarbonImmutable $returnedAt, ?CarbonImmutable $at = null): LeaseAssessment|NoData
    {
        try {
            return $this->assessor->assess(
                $terms->term,
                $terms->allowance,
                $terms->startOdometer,
                $terms->excessCostPerKm,
                $terms->tolerance,
                $samples,
                $returnedAt,
                $at
            );
        } catch (\InvalidArgumentException $e) {
            throw new LeaseTermsRejected(previous: $e);
        }
    }

    /**
     * The readings as samples, and the confirmations: a reading that the car still had later (it had not moved) adds a
     * sample with the same odometer at that instant, so the projection goes on from there. A confirmation is dropped
     * when a later reading comes first (it would contradict it).
     *
     * Nothing counts after $returnedAt (inclusive): a later reading is left out, and a confirmation that runs past it
     * is cut there, as the car still had that odometer at the return.
     *
     * @return list<OdometerSample>
     */
    private function samples(Vehicle $vehicle, ?CarbonImmutable $returnedAt = null): array
    {
        $readings = array_reverse($this->readings->history($vehicle));
        $samples = [];

        foreach ($readings as $index => $reading) {
            if (null !== $returnedAt && $reading->getReadAt()->greaterThan($returnedAt)) {
                break;
            }

            $samples[] = $reading->toSample();
            $confirmedAt = $reading->getConfirmedAt();
            $next = $readings[$index + 1] ?? null;

            if (null !== $confirmedAt && (null === $next || $confirmedAt->lessThan($next->getReadAt()))) {
                $samples[] = new OdometerSample(null !== $returnedAt && $confirmedAt->greaterThan($returnedAt) ? $returnedAt : $confirmedAt, $reading->getOdometer());
            }
        }

        return $samples;
    }
}
