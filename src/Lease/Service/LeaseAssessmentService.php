<?php

declare(strict_types=1);

namespace Polaris\Lease\Service;

use Polaris\Lease\Domain\LeaseAssessment;
use Polaris\Lease\Domain\LeaseAssessor;
use Polaris\Lease\Domain\NoData;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Shared\Domain\OdometerSample;

/**
 * Assesses a lease contract against the odometer samples of its vehicle.
 */
final readonly class LeaseAssessmentService
{
    public function __construct(
        private LeaseAssessor $assessor,
    ) {
    }

    /**
     * @param list<OdometerSample> $samples
     */
    public function assess(LeaseContract $contract, array $samples): LeaseAssessment|NoData
    {
        return $this->assessor->assess(
            $contract->getTerm(),
            $contract->getAllowance(),
            $contract->getStartOdometer(),
            $contract->getExcessCostPerKm(),
            $contract->getTolerance(),
            $samples,
        );
    }
}
