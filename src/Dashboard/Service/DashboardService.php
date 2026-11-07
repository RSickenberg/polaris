<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Service;

use Carbon\CarbonImmutable;
use Polaris\Dashboard\Domain\AllowanceProgress;
use Polaris\Dashboard\Domain\MileageForecast;
use Polaris\Dashboard\Domain\MileageRangeBuilder;
use Polaris\Dashboard\Domain\MileageSeriesBuilder;
use Polaris\Dashboard\Service\Dto\DashboardView;
use Polaris\Dashboard\Service\Enum\DashboardState;
use Polaris\Lease\Domain\Budget;
use Polaris\Lease\Domain\LeaseStatus;
use Polaris\Lease\Domain\NoData;
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
                    $this->leaseSamples($vehicle, $returnedAt),
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
            $contract->getStartOdometer(),
            Budget::evenPace($allowance, $contract->getTerm()),
            $this->leaseTerms->returnedOn($vehicle),
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
}
