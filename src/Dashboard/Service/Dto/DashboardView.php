<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Service\Dto;

use Polaris\Dashboard\Domain\AllowanceProgress;
use Polaris\Dashboard\Domain\MileageRange;
use Polaris\Dashboard\Domain\MileageSeries;
use Polaris\Dashboard\Service\Enum\DashboardState;
use Polaris\Lease\Domain\LeaseAssessment;
use Polaris\Shared\Domain\Distance;

/**
 * Everything the dashboard page shows for one vehicle. The assessment, the allowance and
 * the progress are set from the Draft state onward, and are null for NoLease and NoReading.
 * The mileage curves are set for Active and Ended only.
 */
final readonly class DashboardView
{
    public function __construct(
        public DashboardState $state,
        public ?LeaseAssessment $assessment = null,
        /** The whole lease allowance. */
        public ?Distance $allowance = null,
        public ?AllowanceProgress $progress = null,
        public ?MileageSeries $mileage = null,
        /**
         * The zoom presets of the mileage chart; empty when there is no chart.
         *
         * @var list<MileageRange>
         */
        public array $mileageRanges = [],
    ) {
    }
}
