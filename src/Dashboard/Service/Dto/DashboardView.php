<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Service\Dto;

use Carbon\CarbonImmutable;
use Polaris\Dashboard\Domain\AllowanceProgress;
use Polaris\Dashboard\Domain\MileageRange;
use Polaris\Dashboard\Domain\MileageSeries;
use Polaris\Dashboard\Service\Enum\DashboardState;
use Polaris\Dashboard\Service\Enum\RiskMessage;
use Polaris\Lease\Domain\Budget;
use Polaris\Lease\Domain\LeaseAssessment;
use Polaris\Shared\Domain\Distance;

/**
 * Everything the dashboard page shows for one vehicle. The assessment, the allowance and
 * the progress are set from the Draft state onward, and are null for NoLease and NoReading.
 * The mileage curves are set for Active, Ended and Returned. The comparison with the past predictions is set for
 * Active only.
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
        /** The odometer at the lease start: the distance driven is the latest odometer minus this. */
        public ?Distance $startOdometer = null,
        /** What the allowance is made for per day, week and month, whatever was driven so far. */
        public ?Budget $evenPace = null,
        /** The calendar date (midnight UTC) the user gave the car back; set for Returned only. */
        public ?CarbonImmutable $returnedOn = null,
        /** The last days against what the daily snapshots predicted; set for Active only. */
        public ?ComparisonPanels $comparison = null,
        /** The sentence under the risk level; null without figures. */
        public ?RiskMessage $riskMessage = null,
    ) {
    }
}
