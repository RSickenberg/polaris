<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

use Carbon\CarbonImmutable;

/**
 * The latest figures compared with what the snapshots of the last days predicted, in three ways, one row per window.
 */
final readonly class PredictionComparison
{
    public function __construct(
        /**
         * The distance driven in the window against the snapshot's daily budget times the length of the window.
         *
         * @var list<WindowComparison>
         */
        public array $budget,
        /**
         * The distance driven in the window against the snapshot's blended pace times the length of the window.
         *
         * @var list<WindowComparison>
         */
        public array $pace,
        /**
         * The margin left on the allowance now (allowed to date minus driven) against the snapshot's margin.
         *
         * @var list<WindowComparison>
         */
        public array $margin,
        /**
         * True while the snapshots are being recalculated after an edit of the lease terms: the rows that need them
         * are left out, so that old and new terms never mix.
         */
        public bool $recalculating = false,
        /** The midnight the newest stored snapshot describes; null when none is stored or while recalculating. */
        public ?CarbonImmutable $snapshotCutoffAt = null,
    ) {
    }
}
