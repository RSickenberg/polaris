<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Domain;

use Carbon\CarbonImmutable;

/**
 * A zoom preset: the visible time window of the chart, in UTC instants.
 */
final readonly class MileageRange
{
    public function __construct(
        public MileageRangeKey $key,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {
        if ($to <= $from) {
            throw new \InvalidArgumentException('A range must end after it starts.');
        }
    }
}
