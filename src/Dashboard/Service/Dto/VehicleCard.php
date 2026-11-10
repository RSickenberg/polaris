<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Service\Dto;

use Carbon\CarbonImmutable;
use Polaris\Dashboard\Domain\AllowanceProgress;
use Polaris\Dashboard\Domain\TimeLeft;
use Polaris\Dashboard\Service\Enum\DashboardState;
use Polaris\Lease\Domain\RiskLevel;
use Polaris\Shared\Domain\Distance;

/**
 * What the card of a vehicle shows on the home page. The figures are set for an Active lease only; every other
 * state shows a short sentence instead.
 */
final readonly class VehicleCard
{
    public function __construct(
        public DashboardState $state,
        public ?RiskLevel $riskLevel = null,
        public ?Distance $driven = null,
        /** The whole lease allowance. */
        public ?Distance $allowance = null,
        public ?AllowanceProgress $progress = null,
        public ?Distance $projectedAtEnd = null,
        /** The instant of the latest reading, in UTC. */
        public ?CarbonImmutable $lastReadingAt = null,
        public ?TimeLeft $timeLeft = null,
    ) {
    }

    public function hasFigures(): bool
    {
        return DashboardState::Active === $this->state;
    }
}
