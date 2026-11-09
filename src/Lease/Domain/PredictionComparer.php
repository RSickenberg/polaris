<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

use Carbon\CarbonImmutable;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\OwnerMidnight;

/**
 * Compares what happened since the owner's last midnights with what the daily snapshots predicted then.
 *
 * Every window ends at the latest reading, not at the clock. "Today" starts at the owner's last midnight at or before
 * that reading; the other windows start at the owner's midnight 7, 14 or 30 days before it, and use the snapshot of
 * that local day (never the nearest one). A window with no snapshot, or that starts before the lease did, has no
 * result ("not enough history").
 *
 * One exception: "today" without a snapshot of its own day (the owner's midnight job has not run yet, or was missed)
 * falls back on the latest earlier snapshot and is measured from that snapshot's midnight, so it covers more than the
 * day; the result says by how many days ({@see WindowComparison::$fallbackDays}).
 */
final readonly class PredictionComparer
{
    /**
     * @param array<string, SnapshotFigures> $snapshots the figures by local day (`Y-m-d`)
     *
     * @throws \InvalidArgumentException when the time zone is not an IANA name
     */
    public function compare(
        OdometerTimeline $timeline,
        Projection $latest,
        string $timeZone,
        array $snapshots,
    ): PredictionComparison {
        $lastMidnight = OwnerMidnight::latestAt($latest->asOf, $timeZone);
        $budget = [];
        $pace = [];
        $projectedEnd = [];

        foreach (ComparisonWindow::cases() as $window) {
            $day = $lastMidnight->localDay->subDays($window->days());
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
            $driven = $this->drivenSince($timeline, $start, $latest->asOf);
            $seconds = $latest->asOf->getTimestamp() - $start->getTimestamp();

            $budget[] = new WindowComparison(
                $window,
                $this->againstBudget($figures, $driven, $seconds),
                $fallbackDays,
                $figures->live ?? false,
            );
            $pace[] = new WindowComparison(
                $window,
                $this->againstPace($figures, $driven, $seconds),
                $fallbackDays,
                $figures->live ?? false,
            );
            $projectedEnd[] = new WindowComparison(
                $window,
                $this->againstProjectedEnd($figures, $latest),
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

        return Comparison::of($driven, Distance::fromMetres($expected));
    }

    private function againstPace(?SnapshotFigures $figures, ?Distance $driven, int $seconds): ?Comparison
    {
        if (null === $figures?->blendedPace || null === $driven) {
            return null;
        }

        $expected = (int) round($figures->blendedPace->metresIn($seconds));

        return Comparison::of($driven, Distance::fromMetres($expected));
    }

    private function againstProjectedEnd(?SnapshotFigures $figures, Projection $latest): ?Comparison
    {
        if (null === $figures) {
            return null;
        }

        return Comparison::of($latest->projectedAtEnd, $figures->projectedAtEnd);
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
