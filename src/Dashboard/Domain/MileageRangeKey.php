<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Domain;

/**
 * The zoom presets of the mileage chart.
 */
enum MileageRangeKey: string
{
    /** The 7 days up to now. */
    case Week = 'week';

    /** The 30 days up to now. */
    case Month = 'month';

    /** The year up to now. */
    case Year = 'year';

    /** From now to the lease end: where the projection leads. */
    case Forecast = 'forecast';

    /** The whole lease. */
    case All = 'all';
}
