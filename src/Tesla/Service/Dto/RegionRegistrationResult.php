<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Dto;

use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Service\Enum\RegistrationStatus;

final readonly class RegionRegistrationResult
{
    public function __construct(
        public FleetRegion $region,
        public RegistrationStatus $status,
        public string $message,
    ) {
    }
}
