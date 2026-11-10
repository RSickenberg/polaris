<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

/**
 * How far back a comparison with a past prediction looks. Every window ends at the latest reading; it starts at the
 * owner's midnight this many days before the one that opens the day of that reading.
 */
enum ComparisonWindow: int
{
    /** Since the owner's last midnight. */
    case Today = 0;
    /** The whole of the owner's previous day: from its midnight to the owner's last midnight (or the latest reading). */
    case Yesterday = 1;
    case LastWeek = 7;
    case LastTwoWeeks = 14;
    case LastMonth = 30;

    public function days(): int
    {
        return $this->value;
    }
}
