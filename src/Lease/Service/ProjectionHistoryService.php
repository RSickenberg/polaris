<?php

declare(strict_types=1);

namespace Polaris\Lease\Service;

use Carbon\CarbonImmutable;
use Polaris\Lease\Entity\ProjectionSnapshot;
use Polaris\Lease\Repository\LeaseContractRepository;
use Polaris\Lease\Repository\ProjectionSnapshotRepository;
use Polaris\Lease\Service\Dto\ProjectionSnapshotPoint;
use Polaris\Vehicle\Entity\Vehicle;

/**
 * Reads back the daily snapshots of a vehicle's lease. The effective odometer of a projected-versus-actual chart is
 * not here: it comes from the readings, which have a finer resolution than one point a day.
 */
final readonly class ProjectionHistoryService
{
    public function __construct(
        private LeaseContractRepository $contracts,
        private ProjectionSnapshotRepository $snapshots,
    ) {
    }

    /**
     * @param CarbonImmutable|null $from first local day, inclusive (its calendar date, whatever the zone of the value)
     * @param CarbonImmutable|null $to   last local day, inclusive
     *
     * @return list<ProjectionSnapshotPoint> oldest local day first; empty without a contract
     */
    public function history(Vehicle $vehicle, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $contract = $this->contracts->findForVehicle($vehicle);

        if (null === $contract) {
            return [];
        }

        return array_map(
            static fn (ProjectionSnapshot $snapshot): ProjectionSnapshotPoint => new ProjectionSnapshotPoint(
                $snapshot->getLocalDay(),
                $snapshot->getCutoffAt(),
                $snapshot->getComputedAt(),
                $snapshot->getAsOf(),
                $snapshot->getDriven(),
                $snapshot->getAllowedToDate(),
                $snapshot->getProjectedAtEnd(),
                $snapshot->getDailyBudget(),
                $snapshot->getRiskLevel(),
                $snapshot->getOverallPace(),
                $snapshot->getRecentPace(),
                $snapshot->getBlendedPace(),
            ),
            $this->snapshots->findHistory($contract, $from, $to),
        );
    }
}
