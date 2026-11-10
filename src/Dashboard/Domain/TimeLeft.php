<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Domain;

use Carbon\CarbonImmutable;

/**
 * How long a lease still runs, in whole months and the days beyond them, for a card that says "3 months left"
 * or, in the last month, "12 days left".
 */
final readonly class TimeLeft
{
    private const int SECONDS_PER_DAY = 86_400;

    private function __construct(
        public int $months,
        /** The days beyond the whole months, rounded up: a started day counts. */
        public int $days,
    ) {
    }

    /**
     * @param CarbonImmutable $end the first day after the lease; at or before $now, nothing is left
     */
    public static function between(CarbonImmutable $now, CarbonImmutable $end): self
    {
        if ($end <= $now) {
            return new self(0, 0);
        }

        $months = 0;
        while ($now->addMonthsNoOverflow($months + 1) <= $end) {
            ++$months;
        }

        $rest = $end->getTimestamp() - $now->addMonthsNoOverflow($months)->getTimestamp();

        return new self($months, intdiv($rest + self::SECONDS_PER_DAY - 1, self::SECONDS_PER_DAY));
    }
}
