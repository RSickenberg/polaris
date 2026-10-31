<?php

declare(strict_types=1);

namespace Polaris\Shared\Reporting;

/**
 * A message that acts on the data of one vehicle. The worker finds the owner of the vehicle before it runs, so that an
 * error report of the message is held back when the owner opted out.
 */
interface ReportsForVehicle
{
    /**
     * @return string the ULID (RFC 4122 form) of the vehicle
     */
    public function reportingVehicleId(): string;
}
