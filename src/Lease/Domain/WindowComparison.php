<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

/**
 * One row of a comparison: a window and its result, or no result when the history is not enough.
 */
final readonly class WindowComparison
{
    public function __construct(
        public ComparisonWindow $window,
        /** Null when there is no usable snapshot for the start of the window ("not enough history"). */
        public ?Comparison $comparison,
        /** How many days before its usual start the window really starts: 0 unless "today" fell back (see the comparer). */
        public int $fallbackDays = 0,
        /** True when the figures of the window's start were rebuilt on the fly, not read from a stored snapshot. */
        public bool $live = false,
    ) {
    }
}
