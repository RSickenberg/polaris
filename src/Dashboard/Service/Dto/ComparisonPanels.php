<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Service\Dto;

use Carbon\CarbonImmutable;

/**
 * The three actual-versus-predicted panels of an active lease, one row per period.
 */
final readonly class ComparisonPanels
{
    public function __construct(
        /**
         * Distance driven against the daily budget of the snapshot.
         *
         * @var list<ComparisonRow>
         */
        public array $budget,
        /**
         * Distance driven against the projected pace of the snapshot.
         *
         * @var list<ComparisonRow>
         */
        public array $pace,
        /**
         * Distance projected at the lease end against the one projected by the snapshot.
         *
         * @var list<ComparisonRow>
         */
        public array $projectedEnd,
        /** True while the snapshots are recalculated after an edit of the lease terms: the empty rows say so. */
        public bool $recalculating = false,
        /** When the newest stored snapshot the rows compare with was computed; null when there is none. */
        public ?CarbonImmutable $snapshotComputedAt = null,
    ) {
    }
}
