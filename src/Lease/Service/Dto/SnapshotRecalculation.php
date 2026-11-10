<?php

declare(strict_types=1);

namespace Polaris\Lease\Service\Dto;

/**
 * What a recalculation of the projection snapshots of a lease did, row by row.
 */
final readonly class SnapshotRecalculation
{
    public function __construct(
        /** Rows whose figures changed under the current terms. */
        public int $recomputed = 0,
        /** Rows that cannot be recomputed (no cutoff, before the lease start, no data, not assessable). */
        public int $deleted = 0,
        /** Rows whose figures came out the same. */
        public int $unchanged = 0,
        /** True when another save of the lease was running: nothing was done, try again later. */
        public bool $busy = false,
        /** The newest row that survived, before and after; null when none did. */
        public ?SnapshotChange $latest = null,
    ) {
    }

    public static function busy(): self
    {
        return new self(busy: true);
    }
}
