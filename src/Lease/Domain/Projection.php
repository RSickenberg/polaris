<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

use Carbon\CarbonImmutable;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\DistanceDelta;

/**
 * Where a lease stands at its latest known odometer value, and where it is heading.
 *
 * Every figure is taken at {@see $asOf}: the latest reading inside the lease, or the
 * lease end when a reading comes after it. Distances are measured from the start
 * odometer, so they compare directly with the allowance. They are rounded half up to
 * the metre once, from full-precision values; the margin is the difference of the two
 * rounded distances, so driven + margin = allowed to date exactly.
 */
final readonly class Projection
{
    public function __construct(
        /** The instant every figure is taken at, in UTC, to the second. */
        public CarbonImmutable $asOf,
        /** The distance driven since the lease start. */
        public Distance $driven,
        /** The share of the allowance earned so far, linear over the term. */
        public Distance $allowedToDate,
        /** Allowed to date minus driven: negative when over the allowance. */
        public DistanceDelta $margin,
        /** The pace since the lease start. */
        public Pace $overallPace,
        /** The pace over the 30 days before {@see $asOf}, or null before 30 days of lease. */
        public ?Pace $recentPace,
        /** The weighted mix of both paces, or the overall pace alone when there is no recent one. */
        public Pace $blendedPace,
        /** The distance driven over the whole lease if the blended pace holds until its end. */
        public Distance $projectedAtEnd,
    ) {
    }
}
