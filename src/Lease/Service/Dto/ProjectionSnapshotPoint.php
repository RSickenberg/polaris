<?php

declare(strict_types=1);

namespace Polaris\Lease\Service\Dto;

use Carbon\CarbonImmutable;
use Polaris\Lease\Domain\RiskLevel;
use Polaris\Shared\Domain\Distance;

/**
 * What Polaris projected on one UTC day, for a chart of projected versus effective odometer.
 */
final readonly class ProjectionSnapshotPoint
{
    public function __construct(
        /** The UTC day, at midnight UTC. */
        public CarbonImmutable $day,
        /** When the snapshot was saved. */
        public CarbonImmutable $computedAt,
        /** The instant the figures are taken at: the latest reading, possibly days before {@see $computedAt}. */
        public CarbonImmutable $asOf,
        /** Distance driven since the lease start, at {@see $asOf}. */
        public Distance $driven,
        /** The share of the allowance earned at {@see $asOf}. */
        public Distance $allowedToDate,
        /** The distance driven at the lease end if the pace holds. */
        public Distance $projectedAtEnd,
        /** The distance left to drive per day at {@see $asOf}; zero once ended. */
        public Distance $dailyBudget,
        public RiskLevel $riskLevel,
    ) {
    }
}
