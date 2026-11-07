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
    ) {
    }
}
