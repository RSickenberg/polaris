<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Service;

use Carbon\CarbonImmutable;
use Polaris\Dashboard\Domain\AllowanceProgress;
use Polaris\Dashboard\Domain\TimeLeft;
use Polaris\Dashboard\Service\Dto\VehicleCard;
use Polaris\Dashboard\Service\Enum\DashboardState;
use Polaris\Lease\Domain\NoData;
use Polaris\Lease\Service\LeaseReturnService;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;

/**
 * Builds the card of a vehicle for the home page from the same lease assessment as its dashboard, without
 * recomputing it.
 */
final readonly class VehicleCardService
{
    public function __construct(
        private LeaseTermsService $leaseTerms,
        private LeaseReturnService $leaseReturns,
        private ClockInterface $clock,
    ) {
    }

    public function cardFor(Vehicle $vehicle): VehicleCard
    {
        $contract = $this->leaseTerms->find($vehicle);
        if (null === $contract) {
            return new VehicleCard(DashboardState::NoLease);
        }

        if ($this->leaseReturns->isReturned($vehicle)) {
            return new VehicleCard(DashboardState::Returned);
        }

        $assessment = $this->leaseTerms->assess($vehicle);
        if (null === $assessment || $assessment instanceof NoData) {
            return new VehicleCard(DashboardState::NoReading);
        }

        $state = DashboardState::forStatus($assessment->status);
        if (DashboardState::Active !== $state) {
            return new VehicleCard($state);
        }

        $projection = $assessment->projection;
        $allowance = $contract->getAllowance()->total();

        return new VehicleCard(
            $state,
            $assessment->riskLevel,
            $projection->driven,
            $allowance,
            AllowanceProgress::of($projection->driven, $projection->allowedToDate, $allowance),
            $projection->projectedAtEnd,
            $projection->asOf,
            TimeLeft::between(
                CarbonImmutable::instance($this->clock->now()),
                $contract->getTerm()->exclusiveEnd(),
            ),
        );
    }
}
