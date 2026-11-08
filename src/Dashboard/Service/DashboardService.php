<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Service;

use Carbon\CarbonImmutable;
use Polaris\Dashboard\Domain\AllowanceProgress;
use Polaris\Dashboard\Domain\MileageForecast;
use Polaris\Dashboard\Domain\MileageRangeBuilder;
use Polaris\Dashboard\Domain\MileageSeriesBuilder;
use Polaris\Dashboard\Service\Dto\ComparisonPanels;
use Polaris\Dashboard\Service\Dto\DashboardView;
use Polaris\Dashboard\Service\Enum\DashboardState;
use Polaris\Lease\Domain\Budget;
use Polaris\Lease\Domain\LeaseStatus;
use Polaris\Lease\Domain\NoData;
use Polaris\Lease\Service\ActualVersusPredictedService;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Reading\Service\OdometerReadingService;
use Polaris\Shared\Domain\OdometerSample;
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
        private ActualVersusPredictedService $predictions,
        private ComparisonPanelsMapper $comparisonMapper,
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
        $returnedAt = $contract->getReturnedAt();
        $projection = $assessment->projection;
        $state = match ($assessment->status) {
            LeaseStatus::Draft => DashboardState::Draft,
            LeaseStatus::Active => DashboardState::Active,
            LeaseStatus::Ended => DashboardState::Ended,
            LeaseStatus::Returned => DashboardState::Returned,
        };

        // A lease that has not started has no curve and no zoom presets.
        $mileage = null;
        $ranges = [];

        if (DashboardState::Draft !== $state) {
            $forecast = DashboardState::Active === $state
                ? new MileageForecast($projection->asOf, $projection->driven, $projection->projectedAtEnd)
                : null;
            $mileage = $this->mileageBuilder->build(
                $contract->getTerm()->start(),
                $contract->getTerm()->exclusiveEnd(),
                $contract->getStartOdometer(),
                $allowance,
                $this->leaseSamples($vehicle, $returnedAt),
                $forecast,
            );
            $ranges = $this->rangeBuilder->build(
                $contract->getTerm()->start(),
                $contract->getTerm()->exclusiveEnd(),
                CarbonImmutable::instance($this->clock->now()),
                DashboardState::Active === $state,
            );
        }

        return new DashboardView(
            $state,
            $assessment,
            $allowance,
            AllowanceProgress::of($projection->driven, $projection->allowedToDate, $allowance),
            $mileage,
            $ranges,
            $contract->getStartOdometer(),
            Budget::evenPace($allowance, $contract->getTerm()),
            $this->leaseTerms->returnedOn($vehicle),
            DashboardState::Active === $state ? $this->comparison($vehicle) : null,
        );
    }

    /**
     * The readings of the lease as samples. Readings after the return are stored but not part of the lease any more.
     *
     * @return list<OdometerSample>
     */
    private function leaseSamples(Vehicle $vehicle, ?CarbonImmutable $returnedAt): array
    {
        $samples = [];

        foreach ($this->readings->history($vehicle) as $reading) {
            if (null === $returnedAt || $reading->getReadAt()->lessThanOrEqualTo($returnedAt)) {
                $samples[] = $reading->toSample();
            }
        }

        return $samples;
    }

    private function comparison(Vehicle $vehicle): ?ComparisonPanels
    {
        $comparison = $this->predictions->compare($vehicle);

        return null === $comparison ? null : $this->comparisonMapper->map($comparison);
    }
}
