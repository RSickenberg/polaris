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
 */
final readonly class PredictionComparer
{
    /**
     * @param array<string, SnapshotFigures> $snapshots the figures by local day (`Y-m-d`)
     *
     * @throws \InvalidArgumentException when the time zone is not an IANA name
     */
    public function compare(OdometerTimeline $timeline, Projection $latest, string $timeZone, array $snapshots): PredictionComparison
    {
        $lastMidnight = OwnerMidnight::latestAt($latest->asOf, $timeZone);
        $budget = [];
        $pace = [];
        $projectedEnd = [];

        foreach (ComparisonWindow::cases() as $window) {
            $day = $lastMidnight->localDay->subDays($window->days());
            $start = OwnerMidnight::opening($day, $timeZone)->at;
            $figures = $snapshots[$day->format('Y-m-d')] ?? null;
            $driven = $this->drivenSince($timeline, $start, $latest->asOf);
            $seconds = $latest->asOf->getTimestamp() - $start->getTimestamp();

            $budget[] = new WindowComparison($window, null === $figures || null === $driven ? null : Comparison::of(
                $driven,
                Distance::fromMetres(HalfUp::scale($figures->dailyBudget->metres(), $seconds, Pace::SECONDS_PER_DAY)),
            ));
            $pace[] = new WindowComparison($window, null === $figures?->blendedPace || null === $driven ? null : Comparison::of(
                $driven,
                Distance::fromMetres((int) round($figures->blendedPace->metresIn($seconds))),
            ));
            $projectedEnd[] = new WindowComparison($window, null === $figures ? null : Comparison::of($latest->projectedAtEnd, $figures->projectedAtEnd));
        }

        return new PredictionComparison($budget, $pace, $projectedEnd);
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

        return Distance::fromMetres((int) round($timeline->odometerAt($latest->getTimestamp()) - $timeline->odometerAt($start->getTimestamp())));
    }
}
