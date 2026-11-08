<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

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
         * The distance projected at the lease end now against the one the snapshot projected.
         *
         * @var list<WindowComparison>
         */
        public array $projectedEnd,
    ) {
    }
}
