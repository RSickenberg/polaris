<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Service\Dto;

use Polaris\Dashboard\Service\Enum\ComparisonPeriod;

/**
 * One row of a panel: a period and its figures, or no figures when the history is not enough.
 */
final readonly class ComparisonRow
{
    public function __construct(
        public ComparisonPeriod $period,
        public ?ComparisonFigures $figures,
        /** How many days before its usual start the period really starts (only "today" can, see the Lease comparer). */
        public int $fallbackDays = 0,
    ) {
    }
}
