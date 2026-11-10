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
use Polaris\Dashboard\Service\Enum\RiskMessage;
use Polaris\Lease\Domain\Budget;
use Polaris\Lease\Domain\LeaseAssessment;
use Polaris\Lease\Domain\NoData;
use Polaris\Lease\Domain\RiskLevel;
use Polaris\Lease\Service\ActualVersusPredictedService;
use Polaris\Lease\Service\LeaseReturnService;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;

/**
 * Builds the dashboard of a vehicle from its lease assessment, without recomputing it.
 */
final readonly class DashboardService
{
    public function __construct(
        private LeaseTermsService $leaseTerms,
        private LeaseReturnService $leaseReturns,
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
        $projection = $assessment->projection;
        $state = DashboardState::forStatus($assessment->status);

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
                $this->leaseTerms->readingSamples($vehicle),
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
            $this->leaseReturns->returnedOn($vehicle),
            DashboardState::Active === $state ? $this->comparison($vehicle) : null,
            self::riskMessage($state, $assessment),
        );
    }

    private function comparison(Vehicle $vehicle): ?ComparisonPanels
    {
        $comparison = $this->predictions->compare($vehicle);

        return null === $comparison ? null : $this->comparisonMapper->map($comparison);
    }

    private static function riskMessage(DashboardState $state, LeaseAssessment $assessment): RiskMessage
    {
        $over = RiskLevel::Over === $assessment->riskLevel;

        if (DashboardState::Returned === $state) {
            return $over ? RiskMessage::ReturnedOver : RiskMessage::ReturnedWithin;
        }

        if (DashboardState::Ended === $state) {
            return $over ? RiskMessage::EndedOver : RiskMessage::EndedWithin;
        }

        if (RiskLevel::Watch === $assessment->riskLevel) {
            return RiskMessage::Watch;
        }

        if (!$over) {
            return RiskMessage::Ok;
        }

        $cut = $assessment->weeklyCut;
        $cutHelps = null !== $cut && !$cut->isNegative() && $cut->metres() > 0;

        return $cutHelps ? RiskMessage::Over : RiskMessage::OverWithoutCut;
    }
}
