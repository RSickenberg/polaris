<?php

declare(strict_types=1);

namespace Polaris\Lease\Message;

use Polaris\Shared\Reporting\ReportsForVehicle;

/**
 * Save today's projection snapshot of the lease of a vehicle.
 */
final readonly class SaveProjectionSnapshot implements ReportsForVehicle
{
    public function __construct(
        public string $vehicleId,
        /** How many times the save found another one running and was queued again. */
        public int $attempt = 0,
    ) {
    }

    public function reportingVehicleId(): string
    {
        return $this->vehicleId;
    }
}
