<?php

declare(strict_types=1);

namespace Polaris\Lease\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Lease\Domain\LeaseAssessment;
use Polaris\Lease\Domain\LeaseAssessor;
use Polaris\Lease\Domain\NoData;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Lease\Event\LeaseTermsSaved;
use Polaris\Lease\Form\Model\LeaseContractData;
use Polaris\Lease\Repository\LeaseContractRepository;
use Polaris\Lease\Service\Dto\LeaseTerms;
use Polaris\Lease\Service\Exception\LeaseTermsRejected;
use Polaris\Reading\Service\OdometerReadingService;
use Polaris\Shared\Domain\OdometerSample;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Creates or edits the lease contract of a vehicle and recomputes the projection with the
 * new terms. The only place the rules live: the settings form calls it, and so will the API.
 *
 * The recomputation is synchronous: the projection is a cheap pure calculation, so the
 * caller gets the new assessment right away. Nothing is persisted for it yet; the daily
 * snapshot is #48.
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
        $assessment = $this->assessTerms($terms, $this->samples($vehicle));

        $contract = $this->contracts->findForVehicle($vehicle);
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
            $this->samples($vehicle)
        );
    }

    /**
     * @param list<OdometerSample> $samples
     */
    private function assessTerms(LeaseTerms $terms, array $samples): LeaseAssessment|NoData
    {
        try {
            return $this->assessor->assess(
                $terms->term,
                $terms->allowance,
                $terms->startOdometer,
                $terms->excessCostPerKm,
                $terms->tolerance,
                $samples
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
     * @return list<OdometerSample>
     */
    private function samples(Vehicle $vehicle): array
    {
        $readings = array_reverse($this->readings->history($vehicle));
        $samples = [];

        foreach ($readings as $index => $reading) {
            $samples[] = $reading->toSample();
            $confirmedAt = $reading->getConfirmedAt();
            $next = $readings[$index + 1] ?? null;

            if (null !== $confirmedAt && (null === $next || $confirmedAt->lessThan($next->getReadAt()))) {
                $samples[] = new OdometerSample($confirmedAt, $reading->getOdometer());
            }
        }

        return $samples;
    }
}
