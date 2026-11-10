<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

use Carbon\CarbonImmutable;

/**
 * The hours of the day the owner usually does not drive, as local wall-clock times in the owner's time zone ("from"
 * the usual stop time "until" the usual start time). The period may cross midnight (22:00 to 06:00).
 *
 * Each day's period is rebuilt from the local times every time, never stored as UTC, so a day of 23 or 25 hours has
 * its own quiet length. Where a local time does not exist (the clocks jump over it), PHP resolves it to the first
 * instant that does.
 */
final readonly class QuietHours
{
    private const int MINUTES_PER_HOUR = 60;

    private function __construct(
        private int $fromMinute,
        private int $untilMinute,
        private string $timeZone,
    ) {
    }

    /**
     * @param string $from  the usual stop time, 24-hour "HH:MM"
     * @param string $until the usual start time, 24-hour "HH:MM", different from $from
     *
     * @throws \InvalidArgumentException when a time is not "HH:MM", they are equal, or the zone is not an IANA name
     */
    public static function between(string $from, string $until, string $timeZone): self
    {
        if (!\in_array($timeZone, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an IANA time zone.', $timeZone));
        }

        $fromMinute = self::minuteOfDay($from);
        $untilMinute = self::minuteOfDay($until);

        if ($fromMinute === $untilMinute) {
            throw new \InvalidArgumentException('The quiet period cannot start and end at the same time.');
        }

        return new self($fromMinute, $untilMinute, $timeZone);
    }

    /**
     * How many seconds of the span from $start to $end (Unix timestamps) fall in a quiet period.
     */
    public function secondsBetween(int $start, int $end): int
    {
        if ($end <= $start) {
            return 0;
        }

        $zone = new \DateTimeZone($this->timeZone);
        // The period that started the day before may still run at $start.
        $day = CarbonImmutable::createFromTimestamp($start, $zone)->startOfDay()->subDay();
        $lastDay = CarbonImmutable::createFromTimestamp($end, $zone)->startOfDay();
        $crossesMidnight = $this->untilMinute < $this->fromMinute;
        $quiet = 0;

        while ($day <= $lastDay) {
            $periodStart = $this->instantAt($day, $this->fromMinute, $zone);
            $periodEnd = $this->instantAt($crossesMidnight ? $day->addDay() : $day, $this->untilMinute, $zone);
            $quiet += max(0, min($end, $periodEnd) - max($start, $periodStart));
            $day = $day->addDay();
        }

        return $quiet;
    }

    private function instantAt(CarbonImmutable $day, int $minuteOfDay, \DateTimeZone $zone): int
    {
        $time = \sprintf(
            '%02d:%02d',
            intdiv($minuteOfDay, self::MINUTES_PER_HOUR),
            $minuteOfDay % self::MINUTES_PER_HOUR,
        );
        $local = CarbonImmutable::createFromFormat('!Y-m-d H:i', $day->format('Y-m-d') . ' ' . $time, $zone);
        \assert($local instanceof CarbonImmutable);

        return $local->getTimestamp();
    }

    private static function minuteOfDay(string $time): int
    {
        if (1 !== preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $matches)) {
            throw new \InvalidArgumentException(
                \sprintf('A quiet hours time must be a 24-hour time written HH:MM, got "%s".', $time)
            );
        }

        return (int) $matches[1] * self::MINUTES_PER_HOUR + (int) $matches[2];
    }
}
