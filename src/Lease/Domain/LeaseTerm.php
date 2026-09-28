<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

use Carbon\CarbonImmutable;

/**
 * The period of a lease contract: a whole number of months between two calendar dates.
 *
 * Dates are calendar dates, held as CarbonImmutable at midnight UTC. The end date is
 * kept as the user entered it, with its convention; calculations use the exclusive end.
 * A term is whole when adding its months to the start date gives the exclusive end,
 * clamped to the end of a shorter month: a lease from January 31 runs one month to
 * February 28, or February 29 in a leap year.
 */
final readonly class LeaseTerm
{
    private int $months;

    public function __construct(
        private CarbonImmutable $start,
        private CarbonImmutable $end,
        private EndDateConvention $endDateConvention,
    ) {
        self::assertCalendarDate($start, 'start');
        self::assertCalendarDate($end, 'end');

        $exclusiveEnd = $this->exclusiveEnd();
        $months = ($exclusiveEnd->year - $start->year) * 12 + $exclusiveEnd->month - $start->month;

        if ($months < 1 || !$start->addMonthsNoOverflow($months)->equalTo($exclusiveEnd)) {
            throw new \InvalidArgumentException(\sprintf('The lease term from %s to %s (%s) is not a whole number of months.', $start->toDateString(), $end->toDateString(), $endDateConvention->value));
        }

        $this->months = $months;
    }

    /**
     * Builds a term from the calendar dates the user picked, whatever their time zone:
     * only the year, month and day are kept.
     */
    public static function fromCalendarDates(\DateTimeInterface $start, \DateTimeInterface $end, EndDateConvention $endDateConvention): self
    {
        return new self(self::toCalendarDate($start), self::toCalendarDate($end), $endDateConvention);
    }

    public function start(): CarbonImmutable
    {
        return $this->start;
    }

    /**
     * The end date as entered, to read with {@see endDateConvention()}.
     */
    public function end(): CarbonImmutable
    {
        return $this->end;
    }

    public function endDateConvention(): EndDateConvention
    {
        return $this->endDateConvention;
    }

    /**
     * The first day after the lease.
     */
    public function exclusiveEnd(): CarbonImmutable
    {
        return match ($this->endDateConvention) {
            EndDateConvention::Exclusive => $this->end,
            EndDateConvention::Inclusive => $this->end->addDay(),
        };
    }

    public function months(): int
    {
        return $this->months;
    }

    private static function toCalendarDate(\DateTimeInterface $date): CarbonImmutable
    {
        $calendarDate = CarbonImmutable::createFromFormat('!Y-m-d', $date->format('Y-m-d'), 'UTC');
        \assert($calendarDate instanceof CarbonImmutable);

        return $calendarDate;
    }

    private static function assertCalendarDate(CarbonImmutable $date, string $name): void
    {
        if (0 !== $date->getOffset() || !$date->equalTo($date->startOfDay())) {
            throw new \InvalidArgumentException(\sprintf('The lease %s date must be at midnight UTC, got %s.', $name, $date->toIso8601String()));
        }
    }
}
