<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service;

use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Service\Exception\FleetApiError;
use Polaris\Tesla\Service\Exception\TeslaNotConnected;
use Polaris\Vehicle\Domain\Vin;

/**
 * The only command Polaris sends to a vehicle, apart from reads: a plain REST wake_up (no signed command, no
 * Vehicle Command Protocol). Kept apart from {@see VehicleClient} so that a reader can never wake a car.
 */
interface VehicleCommandClient
{
    /**
     * POST /api/1/vehicles/{vin}/wake_up. A billed call that is never retried.
     *
     * @throws TeslaNotConnected
     * @throws FleetApiError     HTTP 401 and 403 mean the scope is missing (see {@see FleetApiError::isAccessRefused()}), 408 that the car did not answer
     */
    public function wakeUp(TeslaApp $app, Vin $vin): void;
}
