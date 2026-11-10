<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Service\Enum;

/**
 * The period a row of an actual-versus-predicted panel looks at; every period ends at the latest reading.
 */
enum ComparisonPeriod: string
{
    /** Since the owner's last midnight. */
    case Today = 'today';
    case Yesterday = 'yesterday';
    case LastWeek = 'week';
    case LastTwoWeeks = 'two_weeks';
    case LastMonth = 'month';
}
