<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Dto;

use Polaris\Tesla\Service\Enum\VehicleState;
use Polaris\Vehicle\Domain\Vin;

final readonly class VehicleSummary
{
    public function __construct(
        public Vin $vin,
        public ?string $displayName,
        public VehicleState $state,
    ) {
    }
}
