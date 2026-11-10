<?php

declare(strict_types=1);

namespace Polaris\Lease\Message;

use Polaris\Shared\Reporting\ReportsForVehicle;

/**
 * Recalculate every projection snapshot of the lease of a vehicle under its current terms: queued when an edit changed
 * what a projection depends on.
 */
final readonly class RecalculateProjectionSnapshots implements ReportsForVehicle
{
    public function __construct(public string $vehicleId)
    {
    }

    public function reportingVehicleId(): string
    {
        return $this->vehicleId;
    }
}
