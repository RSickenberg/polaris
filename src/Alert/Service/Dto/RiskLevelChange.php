<?php

declare(strict_types=1);

namespace Polaris\Alert\Service\Dto;

use Polaris\Lease\Domain\LeaseAssessment;
use Polaris\Lease\Domain\RiskLevel;
use Polaris\Vehicle\Entity\Vehicle;

/**
 * A vehicle whose risk level got worse, with what an alert needs: the levels and the assessment that
 * caused the change (projection, budget, weekly cut).
 */
final readonly class RiskLevelChange
{
    public function __construct(
        public Vehicle $vehicle,
        public RiskLevel $previous,
        public RiskLevel $current,
        public LeaseAssessment $assessment,
    ) {
    }
}
