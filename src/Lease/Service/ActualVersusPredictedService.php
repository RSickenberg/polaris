<?php

declare(strict_types=1);

namespace Polaris\Lease\Service;

use Carbon\CarbonImmutable;
use Polaris\Lease\Domain\ComparisonWindow;
use Polaris\Lease\Domain\LeaseAssessment;
use Polaris\Lease\Domain\LeaseStatus;
use Polaris\Lease\Domain\PredictionComparer;
use Polaris\Lease\Domain\PredictionComparison;
use Polaris\Lease\Domain\SnapshotFigures;
use Polaris\Lease\Domain\SnapshotPolicy;
use Polaris\Lease\Service\Exception\LeaseTermsRejected;
use Polaris\Shared\Domain\OwnerMidnight;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;

/**
 * Compares what the last days brought with what the daily snapshots predicted ({@see PredictionComparer}): the
 * distance driven against the budget and against the projected pace, and the projected distance at the lease end
 * against the one projected then. The snapshots are those of {@see ProjectionHistoryService}; the odometer and the
 * latest projection are those of the dashboard ({@see LeaseTermsService}).
 *
 * When the owner's midnight job has not saved the snapshot of today (it has not run yet, or was missed), today's
 * figures are rebuilt in memory with {@see LeaseTermsService::assessAt()}, the very computation the job runs, so
 * "today" still compares with the projection of the day's start. Nothing is saved: a stored row stays the record of
 * what was projected then, and the other windows keep needing their own stored snapshot.
 *
 * After an edit of the lease terms the stored snapshots are recalculated under the new ones
 * ({@see ProjectionSnapshotService::recalculate()}). Until that is done they are not used at all: only today's rebuilt
 * figures show, the other rows are empty, and the comparison says it is recalculating.
 */
final readonly class ActualVersusPredictedService
{
    public function __construct(
        private LeaseTermsService $leases,
        private ProjectionHistoryService $history,
        private PredictionComparer $comparer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return PredictionComparison|null null unless the lease is running with at least one reading
     *
     * @throws LeaseTermsRejected when the readings do not fit the terms
     */
    public function compare(Vehicle $vehicle): ?PredictionComparison
    {
        $assessment = $this->leases->assess($vehicle);

        if (!$assessment instanceof LeaseAssessment || LeaseStatus::Active !== $assessment->status) {
            return null;
        }

        $timeline = $this->leases->odometerTimeline($vehicle);

        if (null === $timeline) {
            return null;
        }

        $timeZone = $vehicle->getOwner()->getTimeZone();
        $now = CarbonImmutable::instance($this->clock->now());
        $lastMidnight = OwnerMidnight::latestAt($now, $timeZone);
        $earliest = $lastMidnight->localDay->subDays(ComparisonWindow::LastMonth->days());
        $snapshots = [];
        // Snapshots taken under terms that were edited since say nothing about today's terms: they are left out until
        // the recalculation is done, and today's figures are rebuilt below like on a day without a snapshot.
        $recalculating = $this->leases->find($vehicle)?->hasStaleSnapshots() ?? false;
        $history = $recalculating ? [] : $this->history->history($vehicle, $earliest, $lastMidnight->localDay);

        foreach ($history as $point) {
            $snapshots[$point->localDay->format('Y-m-d')] = new SnapshotFigures(
                $point->projectedAtEnd,
                $point->dailyBudget,
                $point->blendedPace
            );
        }

        $today = $lastMidnight->localDay->format('Y-m-d');

        if (!isset($snapshots[$today])) {
            $rebuilt = $this->rebuild($vehicle, $lastMidnight);

            if ($rebuilt instanceof SnapshotFigures) {
                $snapshots[$today] = $rebuilt;
            }
        }

        $comparison = $this->comparer->compare(
            $timeline,
            $assessment->projection,
            $timeZone,
            $snapshots,
            $now,
            $this->leases->find($vehicle)?->isTodayWholeDay() ?? false,
        );

        // The newest snapshot that knows its midnight (the rows saved before #107 do not).
        $latestCutoff = null;

        foreach (array_reverse($history) as $point) {
            if (null !== $point->cutoffAt) {
                $latestCutoff = $point->cutoffAt;

                break;
            }
        }

        return new PredictionComparison(
            $comparison->budget,
            $comparison->pace,
            $comparison->projectedEnd,
            $recalculating,
            $latestCutoff,
        );
    }

    /**
     * The figures the owner's midnight job would have saved for $midnight; null when it would have saved nothing (no
     * data before the midnight, a lease that was not running then, readings that do not fit the terms).
     */
    private function rebuild(Vehicle $vehicle, OwnerMidnight $midnight): ?SnapshotFigures
    {
        try {
            $assessment = $this->leases->assessAt($vehicle, $midnight->at);
        } catch (LeaseTermsRejected) {
            return null;
        }

        if (!$assessment instanceof LeaseAssessment || !SnapshotPolicy::covers($assessment->status)) {
            return null;
        }

        return new SnapshotFigures(
            $assessment->projection->projectedAtEnd,
            $assessment->budget->perDay,
            $assessment->projection->blendedPace,
            live: true,
        );
    }
}
