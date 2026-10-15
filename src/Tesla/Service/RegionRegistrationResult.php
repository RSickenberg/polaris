<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service;

use Polaris\Tesla\Domain\FleetRegion;

final readonly class RegionRegistrationResult
{
    public function __construct(
        public FleetRegion $region,
        public RegistrationStatus $status,
        public string $message,
    ) {
    }
}
