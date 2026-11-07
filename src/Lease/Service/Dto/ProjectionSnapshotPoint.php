<?php

declare(strict_types=1);

namespace Polaris\Lease\Service\Dto;

use Carbon\CarbonImmutable;
use Polaris\Lease\Domain\Pace;
use Polaris\Lease\Domain\RiskLevel;
use Polaris\Shared\Domain\Distance;

/**
 * What Polaris projected at one of the owner's midnights, for a chart of projected versus effective odometer and for
 * the comparison of the last days with the prediction. Rows saved before the daily jobs followed the owner's midnight
 * have no cutoff and no pace (null).
 */
final readonly class ProjectionSnapshotPoint
{
    public function __construct(
        /** The calendar date the midnight opens (the row of 7 November is taken at the start of the 7th), as midnight UTC. A label, not an instant. */
        public CarbonImmutable $localDay,
        /** The owner's midnight the figures are taken at (only readings up to it count), UTC; null for an old row. */
        public ?CarbonImmutable $cutoffAt,
        /** When the snapshot was saved. */
        public CarbonImmutable $computedAt,
        /** The instant the figures are taken at: the latest reading up to the cutoff, possibly days before it. */
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
        /** The pace since the lease start. */
        public ?Pace $overallPace,
        /** The pace over the 30 days before {@see $asOf}; null before 30 days of lease. */
        public ?Pace $recentPace,
        /** The pace the projection used (the weighted mix, or the overall pace alone without a recent one). */
        public ?Pace $blendedPace,
    ) {
    }
}
