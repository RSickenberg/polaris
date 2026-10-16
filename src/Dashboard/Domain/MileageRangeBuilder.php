<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Domain;

use Carbon\CarbonImmutable;

/**
 * Builds the zoom presets of the mileage chart.
 *
 * The windows that look back end at the current instant and never begin before the lease
 * start. The forecast window runs from now to the lease end and is only offered while the
 * chart draws a projection. A window that would be empty (the lease has not run that long,
 * or it ended before the window) is left out; the whole lease is always there.
 */
final readonly class MileageRangeBuilder
{
    /**
     * @return non-empty-list<MileageRange>
     */
    public function build(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $now, bool $hasProjection): array
    {
        $ranges = [];

        foreach ([
            [MileageRangeKey::Week, $now->subDays(7)],
            [MileageRangeKey::Month, $now->subDays(30)],
            [MileageRangeKey::Year, $now->subYear()],
        ] as [$key, $from]) {
            $from = $from->max($start);
            if ($from < $now && $from < $end) {
                $ranges[] = new MileageRange($key, $from, $now);
            }
        }

        if ($hasProjection && $now < $end) {
            $ranges[] = new MileageRange(MileageRangeKey::Forecast, $now, $end);
        }

        $ranges[] = new MileageRange(MileageRangeKey::All, $start, $end);

        return $ranges;
    }
}
