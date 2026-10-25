<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service;

use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Service\Dto\OdometerReadResult;
use Polaris\Tesla\Service\Enum\NoReadingReason;
use Polaris\Tesla\Service\Enum\VehicleState;
use Polaris\Tesla\Service\Exception\FleetApiError;
use Polaris\Tesla\Service\Exception\TeslaNotConnected;
use Polaris\Vehicle\Domain\Vin;

/**
 * Reads the odometer of one vehicle without ever waking it: checks the state, then asks the car only
 * when it is online. An asleep or offline car, or one that falls asleep between the two calls (HTTP 408,
 * not retried), gives no reading and a reason. `vehicle_data_cached` is not used because a real cached
 * answer has no odometer. Comparing a reading with the last stored one, and saving it, belong to the
 * fetch job.
 */
final readonly class TeslaOdometerReader
{
    public function __construct(private VehicleClient $vehicles)
    {
    }

    /**
     * @throws TeslaNotConnected
     * @throws FleetApiError
     */
    public function read(TeslaApp $app, Vin $vin): OdometerReadResult
    {
        $state = $this->vehicles->vehicleState($app, $vin);

        if (VehicleState::Online !== $state) {
            return OdometerReadResult::none(VehicleState::Asleep === $state ? NoReadingReason::Asleep : NoReadingReason::Offline);
        }

        try {
            return OdometerReadResult::read($this->vehicles->vehicleData($app, $vin));
        } catch (FleetApiError $failure) {
            if ($failure->isVehicleNotOnline()) {
                return OdometerReadResult::none(NoReadingReason::Unavailable);
            }

            throw $failure;
        }
    }
}
