<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Service;

use Carbon\CarbonImmutable;
use Polaris\Dashboard\Domain\AllowanceProgress;
use Polaris\Dashboard\Domain\MileageForecast;
use Polaris\Dashboard\Domain\MileageRangeBuilder;
use Polaris\Dashboard\Domain\MileageSeriesBuilder;
use Polaris\Lease\Domain\LeaseStatus;
use Polaris\Lease\Domain\NoData;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Reading\Service\OdometerReadingService;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;

/**
 * Builds the dashboard of a vehicle from its lease assessment, without recomputing it.
 */
final readonly class DashboardService
{
    public function __construct(
        private LeaseTermsService $leaseTerms,
        private OdometerReadingService $readings,
        private MileageSeriesBuilder $mileageBuilder,
        private MileageRangeBuilder $rangeBuilder,
        private ClockInterface $clock,
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
        $projection = $assessment->projection;
        $state = match ($assessment->status) {
            LeaseStatus::Draft => DashboardState::Draft,
            LeaseStatus::Active => DashboardState::Active,
            LeaseStatus::Ended => DashboardState::Ended,
        };

        return new DashboardView(
            $state,
            $assessment,
            $allowance,
            AllowanceProgress::of($projection->driven, $projection->allowedToDate, $allowance),
            DashboardState::Draft === $state
                ? null
                : $this->mileageBuilder->build(
                    $contract->getTerm()->start(),
                    $contract->getTerm()->exclusiveEnd(),
                    $contract->getStartOdometer(),
                    $allowance,
                    array_map(
                        static fn (OdometerReading $reading) => $reading->toSample(),
                        $this->readings->history($vehicle),
                    ),
                    DashboardState::Active === $state
                        ? new MileageForecast($projection->asOf, $projection->driven, $projection->projectedAtEnd)
                        : null,
                ),
            DashboardState::Draft === $state
                ? []
                : $this->rangeBuilder->build(
                    $contract->getTerm()->start(),
                    $contract->getTerm()->exclusiveEnd(),
                    CarbonImmutable::instance($this->clock->now()),
                    DashboardState::Active === $state,
                ),
        );
    }
}
