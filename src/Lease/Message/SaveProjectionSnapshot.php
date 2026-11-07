<?php

declare(strict_types=1);

namespace Polaris\Lease\Message;

use Polaris\Shared\Reporting\ReportsForVehicle;

/**
 * Save the projection snapshot of the lease of a vehicle at the owner's midnight M (the instant and the local day it
 * opens), whenever the message is handled: the figures are taken at M.
 */
final readonly class SaveProjectionSnapshot implements ReportsForVehicle
{
    public function __construct(
        public string $vehicleId,
        /** The calendar date M opens, as `Y-m-d`. */
        public string $localDay,
        /** M as a Unix timestamp. */
        public int $midnightTimestamp,
        /** How many times the save found another one running and was queued again. */
        public int $attempt = 0,
    ) {
    }

    public function reportingVehicleId(): string
    {
        return $this->vehicleId;
    }
}
