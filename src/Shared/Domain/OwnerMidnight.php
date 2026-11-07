<?php

declare(strict_types=1);

namespace Polaris\Shared\Domain;

use Carbon\CarbonImmutable;

/**
 * The midnight M that opens a local calendar day of an owner: the instant the daily jobs and the daily projection
 * snapshot are taken at. The conversion is redone every time from the time zone, never stored as a UTC time, so
 * daylight saving needs no restart (a day of 23 or 25 hours has its midnight like any other).
 *
 * M is the first instant of the local day. Where midnight does not exist that day (the clocks jump over it), it is the
 * first instant that does, as PHP resolves it. The local day is the calendar date M opens, kept as midnight UTC so
 * that it fits a date column; it is a label, not an instant.
 */
final readonly class OwnerMidnight
{
    /** How long after M a job is due: a reading stamped just before M can still be on its way to the database. */
    public const int DELAY_SECONDS = 300;

    private function __construct(
        /** M, the first instant of the local day, in UTC. */
        public CarbonImmutable $at,
        /** The calendar date M opens, as midnight UTC. */
        public CarbonImmutable $localDay,
    ) {
    }

    /**
     * A midnight as it was recorded (in a message): the instant M and the calendar date it opened.
     *
     * @throws \InvalidArgumentException when the date is not midnight UTC
     */
    public static function of(CarbonImmutable $at, CarbonImmutable $localDay): self
    {
        if (0 !== $localDay->getOffset() || '00:00:00' !== $localDay->format('H:i:s')) {
            throw new \InvalidArgumentException(\sprintf('A local day must be midnight UTC, got %s.', $localDay->toIso8601String()));
        }

        return new self($at->utc(), $localDay);
    }

    /**
     * The latest midnight at or before $now.
     *
     * @throws \InvalidArgumentException when the time zone is not an IANA name
     */
    public static function latestAt(CarbonImmutable $now, string $timeZone): self
    {
        $local = $now->setTimezone(self::zone($timeZone))->startOfDay();
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $local->format('Y-m-d'), TimeZone::UTC);
        \assert($day instanceof CarbonImmutable);

        return new self($local->utc(), $day);
    }

    /**
     * The latest midnight whose jobs are due at $now: M plus {@see self::DELAY_SECONDS} has passed.
     *
     * @throws \InvalidArgumentException when the time zone is not an IANA name
     */
    public static function latestDue(CarbonImmutable $now, string $timeZone): self
    {
        return self::latestAt($now->subSeconds(self::DELAY_SECONDS), $timeZone);
    }

    /**
     * The midnight that opens the local calendar date $localDay (any time of the day) in $timeZone.
     *
     * @throws \InvalidArgumentException when the time zone is not an IANA name
     */
    public static function opening(\DateTimeInterface $localDay, string $timeZone): self
    {
        $local = CarbonImmutable::createFromFormat('!Y-m-d', $localDay->format('Y-m-d'), self::zone($timeZone));
        \assert($local instanceof CarbonImmutable);

        return self::latestAt($local, $timeZone);
    }

    private static function zone(string $timeZone): \DateTimeZone
    {
        try {
            return new \DateTimeZone($timeZone);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a time zone.', $timeZone), 0, $e);
        }
    }
}
