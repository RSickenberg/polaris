<?php

declare(strict_types=1);

namespace Polaris\Alert\Message;

use Polaris\Shared\Reporting\ReportsForVehicle;

/**
 * The risk level of a vehicle may have changed: a reading was stored or confirmed, or the lease terms were saved.
 */
final readonly class CheckRiskLevel implements ReportsForVehicle
{
    public function __construct(
        public string $vehicleId,
        /** How many times the check found another one running and was queued again. */
        public int $attempt = 0,
    ) {
    }

    public function reportingVehicleId(): string
    {
        return $this->vehicleId;
    }
}
