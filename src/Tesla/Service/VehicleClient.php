<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service;

use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Service\Dto\OdometerReading;
use Polaris\Tesla\Service\Dto\VehicleSummary;
use Polaris\Tesla\Service\Enum\VehicleState;
use Polaris\Tesla\Service\Exception\FleetApiError;
use Polaris\Tesla\Service\Exception\TeslaNotConnected;
use Polaris\Vehicle\Domain\Vin;

/**
 * Read-only access to the vehicles of a connected Tesla account. Callers pass the app and never see
 * a token. No method wakes the car.
 */
interface VehicleClient
{
    /**
     * The first page of the vehicles of the account (Tesla pages by 100 and documents no paging parameter).
     *
     * @return list<VehicleSummary>
     *
     * @throws TeslaNotConnected
     * @throws FleetApiError
     */
    public function listVehicles(TeslaApp $app): array;

    /**
     * Tesla advises checking the state before a paid call such as {@see self::vehicleData()}.
     *
     * @throws TeslaNotConnected
     * @throws FleetApiError
     */
    public function vehicleState(TeslaApp $app, Vin $vin): VehicleState;

    /**
     * A live read: a billed call that only an online car answers.
     *
     * @throws TeslaNotConnected
     * @throws FleetApiError     HTTP 408 (see {@see FleetApiError::isVehicleNotOnline()}) when the car is not online
     */
    public function vehicleData(TeslaApp $app, Vin $vin): OdometerReading;
}
