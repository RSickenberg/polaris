<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

use Polaris\Shared\Domain\Distance;

/**
 * What a past projection snapshot predicted, in the terms the comparison needs.
 */
final readonly class SnapshotFigures
{
    public function __construct(
        /** The distance driven at the lease end that the snapshot projected. */
        public Distance $projectedAtEnd,
        /** The distance per day the snapshot said to drive. */
        public Distance $dailyBudget,
        /** The pace the snapshot projected with; null for a snapshot saved before the pace was stored. */
        public ?Pace $blendedPace,
    ) {
    }
}
