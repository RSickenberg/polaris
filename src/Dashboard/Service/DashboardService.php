<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Service;

use Polaris\Dashboard\Domain\AllowanceProgress;
use Polaris\Lease\Domain\LeaseStatus;
use Polaris\Lease\Domain\NoData;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Vehicle\Entity\Vehicle;

/**
 * Builds the dashboard of a vehicle from its lease assessment, without recomputing it.
 */
final readonly class DashboardService
{
    public function __construct(
        private LeaseTermsService $leaseTerms,
    ) {
    }

    public function viewFor(Vehicle $vehicle): DashboardView
    {
        $contract = $this->leaseTerms->find($vehicle);
        if (null === $contract) {
            return new DashboardView(DashboardState::NoLease);
        }

        $assessment = $this->leaseTerms->assess($vehicle);
        if (null === $assessment || $assessment instanceof NoData) {
            return new DashboardView(DashboardState::NoReading);
        }

        $allowance = $contract->getAllowance()->total();

        return new DashboardView(
            match ($assessment->status) {
                LeaseStatus::Draft => DashboardState::Draft,
                LeaseStatus::Active => DashboardState::Active,
                LeaseStatus::Ended => DashboardState::Ended,
            },
            $assessment,
            $allowance,
            AllowanceProgress::of($assessment->projection->driven, $assessment->projection->allowedToDate, $allowance),
        );
    }
}
