<?php

declare(strict_types=1);

namespace Polaris\Fetching\Domain;

use Carbon\CarbonImmutable;

/**
 * Turns the wall-clock slots of a user into UTC instants for the local days around "now". The conversion
 * is redone every time (never stored as a fixed UTC time), so a slot keeps its local time across a
 * daylight saving change. A local time that does not exist that day (the skipped hour of spring) moves
 * forward by the length of the gap, as PHP does; one that happens twice (autumn) is the first occurrence.
 */
final readonly class SlotCalculator
{
    /**
     * The UTC instants of the slots on the local day before $now and on the local day of $now, oldest first,
     * without duplicates. The previous day is there for slots just before local midnight, whose window
     * is still open after it.
     *
     * @param list<LocalTime> $slots
     *
     * @return list<CarbonImmutable>
     *
     * @throws \InvalidArgumentException when the time zone is not an IANA name
     */
    public function around(CarbonImmutable $now, string $timeZone, array $slots): array
    {
        try {
            $zone = new \DateTimeZone($timeZone);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a time zone.', $timeZone), 0, $e);
        }

        $today = $now->setTimezone($zone)->startOfDay();
        $instants = [];

        foreach ([$today->subDay(), $today] as $day) {
            foreach ($slots as $slot) {
                $instant = $day->setTime($slot->hour, $slot->minute)->utc();
                $instants[$instant->getTimestamp()] = $instant;
            }
        }

        ksort($instants);

        return array_values($instants);
    }
}
