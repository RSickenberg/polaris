<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

use Carbon\CarbonImmutable;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\OwnerMidnight;

/**
 * Compares what happened since the owner's last midnights with what the daily snapshots predicted then.
 *
 * Every window ends at the latest reading. It starts at the owner's midnight 0, 7, 14 or 30 days before the owner's
 * current day (read from the clock, not from the reading), and uses the snapshot of that local day (never the nearest
 * one). A window with no snapshot, or that starts before the lease did, has no result ("not enough history").
 *
 * "Yesterday" is the closed previous day: its midnight to the owner's current midnight (or the latest reading when that
 * is earlier), against the snapshot of that day; its projected end is the snapshot of today's midnight against it.
 *
 * "Today" with no reading since the owner's current midnight has nothing to measure: it is marked
 * {@see WindowComparison::$awaitingReading} instead of showing the figures of an earlier day.
 *
 * On a short window the share of the expected distance is noise (5 km driven against 1 km expected is +400 %): below
 * {@see self::MIN_SECONDS_FOR_RATIO} the distances stay and the percentage goes.
 *
 * One exception: "today" without a snapshot of its own day (the owner's midnight job has not run yet, or was missed)
 * falls back on the latest earlier snapshot and is measured from that snapshot's midnight, so it covers more than the
 * day; the result says by how many days ({@see WindowComparison::$fallbackDays}).
 */
final readonly class PredictionComparer
{
    /** A window shorter than this (from its start to the latest reading) shows no percentage: 6 hours. */
    public const int MIN_SECONDS_FOR_RATIO = 21_600;

    /**
     * @param array<string, SnapshotFigures> $snapshots     the figures by local day (`Y-m-d`)
     * @param CarbonImmutable                $now           decides which local day is "today"; the windows end at the latest reading
     * @param bool                           $todayWholeDay whether "today" is measured against what was expected for the
     *                                                      whole day, instead of the part of it up to the latest reading
     *
     * @throws \InvalidArgumentException when the time zone is not an IANA name
     */
    public function compare(
        OdometerTimeline $timeline,
        Projection $latest,
        string $timeZone,
        array $snapshots,
        CarbonImmutable $now,
        bool $todayWholeDay = false,
    ): PredictionComparison {
        $lastMidnight = OwnerMidnight::latestAt($now, $timeZone);
        $budget = [];
        $pace = [];
        $projectedEnd = [];

        foreach (ComparisonWindow::cases() as $window) {
            $day = $lastMidnight->localDay->subDays($window->days());

            if (ComparisonWindow::Today === $window && $latest->asOf < $lastMidnight->at) {
                $waiting = new WindowComparison($window, null, awaitingReading: true);
                $budget[] = $waiting;
                $pace[] = $waiting;
                $projectedEnd[] = $waiting;

                continue;
            }

            $figures = $snapshots[$day->format('Y-m-d')] ?? null;
            $fallbackDays = 0;

            if (null === $figures && ComparisonWindow::Today === $window) {
                $earlier = $this->latestSnapshotDayBefore($day, $snapshots);

                if ($earlier instanceof CarbonImmutable) {
                    $fallbackDays = (int) $earlier->diffInDays($day, true);
                    $day = $earlier;
                    $figures = $snapshots[$day->format('Y-m-d')];
                }
            }

            $start = OwnerMidnight::opening($day, $timeZone)->at;
            // Yesterday is a closed day: it ends at the owner's last midnight, or at the latest reading if that came first.
            $end = ComparisonWindow::Yesterday === $window ? min($lastMidnight->at, $latest->asOf) : $latest->asOf;
            $driven = $end > $start ? $this->drivenSince($timeline, $start, $end) : null;
            $seconds = $end->getTimestamp() - $start->getTimestamp();
            // By default every window expects what fits between its start and the latest reading. For today the owner
            // may choose the whole day instead: with the snapshot of its own day (no fallback, which spans several
            // days) the expectation is a full local day, 23 or 25 hours on a daylight saving change.
            $expectedSeconds = $todayWholeDay && ComparisonWindow::Today === $window && 0 === $fallbackDays
                ? OwnerMidnight::opening($day->addDay(), $timeZone)->at->getTimestamp() - $start->getTimestamp()
                : $seconds;

            $budget[] = new WindowComparison(
                $window,
                $this->againstBudget($figures, $driven, $expectedSeconds),
                $fallbackDays,
                $figures->live ?? false,
            );
            $pace[] = new WindowComparison(
                $window,
                $this->againstPace($figures, $driven, $expectedSeconds),
                $fallbackDays,
                $figures->live ?? false,
            );
            $projectedEnd[] = new WindowComparison(
                $window,
                $this->againstProjectedEnd(
                    $figures,
                    ComparisonWindow::Yesterday === $window
                        ? ($snapshots[$lastMidnight->localDay->format('Y-m-d')]->projectedAtEnd ?? null)
                        : $latest->projectedAtEnd,
                ),
                $fallbackDays,
                $figures->live ?? false,
            );
        }

        return new PredictionComparison($budget, $pace, $projectedEnd);
    }

    /**
     * The local day of the latest snapshot strictly before $day, or null when there is none.
     *
     * @param array<string, SnapshotFigures> $snapshots the figures by local day (`Y-m-d`)
     */
    private function latestSnapshotDayBefore(CarbonImmutable $day, array $snapshots): ?CarbonImmutable
    {
        $limit = $day->format('Y-m-d');
        $earlier = array_filter(
            array_keys($snapshots),
            static fn (string $key): bool => $key < $limit,
        );

        if ([] === $earlier) {
            return null;
        }

        return CarbonImmutable::parse(max($earlier), 'UTC');
    }

    private function againstBudget(?SnapshotFigures $figures, ?Distance $driven, int $seconds): ?Comparison
    {
        if (null === $figures || null === $driven) {
            return null;
        }

        $expected = HalfUp::scale($figures->dailyBudget->metres(), $seconds, Pace::SECONDS_PER_DAY);

        return $this->withoutNoise(Comparison::of($driven, Distance::fromMetres($expected)), $seconds);
    }

    private function againstPace(?SnapshotFigures $figures, ?Distance $driven, int $seconds): ?Comparison
    {
        if (null === $figures?->blendedPace || null === $driven) {
            return null;
        }

        $expected = (int) round($figures->blendedPace->metresIn($seconds));

        return $this->withoutNoise(Comparison::of($driven, Distance::fromMetres($expected)), $seconds);
    }

    private function withoutNoise(Comparison $comparison, int $seconds): Comparison
    {
        return $seconds < self::MIN_SECONDS_FOR_RATIO ? $comparison->withoutRatio() : $comparison;
    }

    private function againstProjectedEnd(?SnapshotFigures $figures, ?Distance $projectedNow): ?Comparison
    {
        if (null === $figures || null === $projectedNow) {
            return null;
        }

        return Comparison::of($projectedNow, $figures->projectedAtEnd);
    }

    /**
     * The distance driven between the start of a window and the latest reading; null when the timeline does not reach
     * back to the start (before the lease began).
     */
    private function drivenSince(OdometerTimeline $timeline, CarbonImmutable $start, CarbonImmutable $latest): ?Distance
    {
        if (!$timeline->covers($start->getTimestamp())) {
            return null;
        }

        $driven = $timeline->odometerAt($latest->getTimestamp()) - $timeline->odometerAt($start->getTimestamp());

        return Distance::fromMetres((int) round($driven));
    }
}
