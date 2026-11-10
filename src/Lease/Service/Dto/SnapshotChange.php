<?php

declare(strict_types=1);

namespace Polaris\Lease\Service\Dto;

use Polaris\Lease\Domain\RiskLevel;
use Polaris\Shared\Domain\Distance;

/**
 * What a recalculation did to one snapshot: the figures it held before and after. Reported for the newest row that
 * survived, the prediction closest to today's.
 */
final readonly class SnapshotChange
{
    public function __construct(
        /** The local day of the row, `Y-m-d`. */
        public string $localDay,
        public Distance $projectedAtEndBefore,
        public Distance $projectedAtEndAfter,
        public Distance $dailyBudgetBefore,
        public Distance $dailyBudgetAfter,
        public RiskLevel $riskLevelBefore,
        public RiskLevel $riskLevelAfter,
    ) {
    }
}
