<?php

declare(strict_types=1);

namespace Polaris\Lease\Service;

use Polaris\Lease\Domain\ComparisonWindow;
use Polaris\Lease\Domain\LeaseAssessment;
use Polaris\Lease\Domain\LeaseStatus;
use Polaris\Lease\Domain\PredictionComparer;
use Polaris\Lease\Domain\PredictionComparison;
use Polaris\Lease\Domain\SnapshotFigures;
use Polaris\Lease\Service\Exception\LeaseTermsRejected;
use Polaris\Shared\Domain\OwnerMidnight;
use Polaris\Vehicle\Entity\Vehicle;

/**
 * Compares what the last days brought with what the daily snapshots predicted ({@see PredictionComparer}): the
 * distance driven against the budget and against the projected pace, and the projected distance at the lease end
 * against the one projected then. The snapshots are those of {@see ProjectionHistoryService}; the odometer and the
 * latest projection are those of the dashboard ({@see LeaseTermsService}).
 */
final readonly class ActualVersusPredictedService
{
    public function __construct(
        private LeaseTermsService $leases,
        private ProjectionHistoryService $history,
        private PredictionComparer $comparer,
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
        $lastMidnight = OwnerMidnight::latestAt($assessment->projection->asOf, $timeZone);
        $earliest = $lastMidnight->localDay->subDays(ComparisonWindow::LastMonth->days());
        $snapshots = [];

        foreach ($this->history->history($vehicle, $earliest, $lastMidnight->localDay) as $point) {
            $snapshots[$point->localDay->format('Y-m-d')] = new SnapshotFigures($point->projectedAtEnd, $point->dailyBudget, $point->blendedPace);
        }

        return $this->comparer->compare($timeline, $assessment->projection, $timeZone, $snapshots);
    }
}
