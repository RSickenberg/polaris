<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

use Polaris\Shared\Domain\Distance;

/**
 * The distance left to drive per day, per week and per month to end the lease exactly on
 * the allowance: remaining / time left, each rounded half up to the metre.
 *
 * A day is 86,400 s and a week 7 days. A month is one lease month: the term divided by its
 * number of months, so a 60-month lease has 60 equal months.
 */
final readonly class Budget
{
    public const int SECONDS_PER_WEEK = 7 * Pace::SECONDS_PER_DAY;

    public function __construct(
        public Distance $perDay,
        public Distance $perWeek,
        public Distance $perMonth,
    ) {
    }

    public static function none(): self
    {
        $zero = Distance::fromMetres(0);

        return new self($zero, $zero, $zero);
    }

    /**
     * @param int $secondsLeft the time between the latest reading and the lease end; 0 or less gives no budget
     */
    public static function spread(Distance $remaining, LeaseTerm $term, int $secondsLeft): self
    {
        if ($secondsLeft <= 0 || 0 === $remaining->metres()) {
            return self::none();
        }

        $termSeconds = $term->exclusiveEnd()->getTimestamp() - $term->start()->getTimestamp();

        return new self(
            Distance::fromMetres(HalfUp::scale($remaining->metres(), Pace::SECONDS_PER_DAY, $secondsLeft)),
            Distance::fromMetres(HalfUp::scale($remaining->metres(), self::SECONDS_PER_WEEK, $secondsLeft)),
            Distance::fromMetres(HalfUp::scale($remaining->metres(), $termSeconds, $term->months() * $secondsLeft)),
        );
    }
}
