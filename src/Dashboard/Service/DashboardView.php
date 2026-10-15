<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Service;

use Polaris\Dashboard\Domain\AllowanceProgress;
use Polaris\Lease\Domain\LeaseAssessment;
use Polaris\Shared\Domain\Distance;

/**
 * Everything the dashboard page shows for one vehicle. The assessment, the allowance and
 * the progress are set from the Draft state onward, and are null for NoLease and NoReading.
 */
final readonly class DashboardView
{
    public function __construct(
        public DashboardState $state,
        public ?LeaseAssessment $assessment = null,
        /** The whole lease allowance. */
        public ?Distance $allowance = null,
        public ?AllowanceProgress $progress = null,
    ) {
    }
}
