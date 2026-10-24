<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Tesla\Service;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Account\Entity\User;
use Polaris\Shared\Domain\Distance;
use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Service\Dto\OdometerReading;
use Polaris\Tesla\Service\Dto\VehicleSummary;
use Polaris\Tesla\Service\Enum\FleetApiOperation;
use Polaris\Tesla\Service\Enum\NoReadingReason;
use Polaris\Tesla\Service\Enum\VehicleState;
use Polaris\Tesla\Service\Exception\FleetApiError;
use Polaris\Tesla\Service\TeslaOdometerReader;
use Polaris\Tesla\Service\VehicleClient;
use Polaris\Tests\Support\FleetApiResponses;
use Polaris\Vehicle\Domain\Vin;

#[CoversClass(TeslaOdometerReader::class)]
final class TeslaOdometerReaderTest extends TestCase
{
    public function testAnOnlineCarGivesALiveReading(): void
    {
        $dataCalls = new \ArrayObject();
        $vehicles = $this->vehicles($dataCalls, VehicleState::Online);

        $result = new TeslaOdometerReader($vehicles)->read($this->app(), new Vin(FleetApiResponses::VIN));

        self::assertTrue($result->hasReading());
        self::assertSame(20_000_000, $result->reading?->odometer->metres());
        self::assertNull($result->reason);
        self::assertCount(1, $dataCalls);
    }

    public function testAnAsleepCarGivesNoReadingAndIsNotAsked(): void
    {
        $dataCalls = new \ArrayObject();
        $vehicles = $this->vehicles($dataCalls, VehicleState::Asleep);

        $result = new TeslaOdometerReader($vehicles)->read($this->app(), new Vin(FleetApiResponses::VIN));

        self::assertFalse($result->hasReading());
        self::assertSame(NoReadingReason::Asleep, $result->reason);
        self::assertCount(0, $dataCalls);
    }

    public function testAnOfflineCarGivesNoReadingAndIsNotAsked(): void
    {
        $dataCalls = new \ArrayObject();
        $vehicles = $this->vehicles($dataCalls, VehicleState::Offline);

        $result = new TeslaOdometerReader($vehicles)->read($this->app(), new Vin(FleetApiResponses::VIN));

        self::assertSame(NoReadingReason::Offline, $result->reason);
        self::assertCount(0, $dataCalls);
    }

    public function testACarThatFellAsleepBetweenTheCallsGivesNoReadingAndIsAskedOnce(): void
    {
        $dataCalls = new \ArrayObject();
        $vehicles = $this->vehicles($dataCalls, VehicleState::Online, FleetApiError::fromResponse(FleetApiOperation::VehicleData, FleetRegion::EuropeMiddleEastAfrica, 408, FleetApiResponses::vehicleNotOnline()));

        $result = new TeslaOdometerReader($vehicles)->read($this->app(), new Vin(FleetApiResponses::VIN));

        self::assertSame(NoReadingReason::Unavailable, $result->reason);
        self::assertCount(1, $dataCalls);
    }

    public function testOtherFailuresOfTheLiveCallAreNotHidden(): void
    {
        $vehicles = $this->vehicles(new \ArrayObject(), VehicleState::Online, FleetApiError::fromResponse(FleetApiOperation::VehicleData, FleetRegion::EuropeMiddleEastAfrica, 503, ''));

        $this->expectException(FleetApiError::class);

        new TeslaOdometerReader($vehicles)->read($this->app(), new Vin(FleetApiResponses::VIN));
    }

    /**
     * @param \ArrayObject<int, true> $dataCalls filled with one entry per live read
     */
    private function vehicles(\ArrayObject $dataCalls, VehicleState $state, ?FleetApiError $dataFailure = null): VehicleClient
    {
        return new class($dataCalls, $state, $dataFailure) implements VehicleClient {
            /**
             * @param \ArrayObject<int, true> $dataCalls
             */
            public function __construct(private \ArrayObject $dataCalls, private VehicleState $state, private ?FleetApiError $dataFailure)
            {
            }

            /**
             * @return list<VehicleSummary>
             */
            public function listVehicles(TeslaApp $app): array
            {
                return [];
            }

            public function vehicleState(TeslaApp $app, Vin $vin): VehicleState
            {
                return $this->state;
            }

            public function vehicleData(TeslaApp $app, Vin $vin): OdometerReading
            {
                $this->dataCalls[] = true;

                return $this->dataFailure instanceof FleetApiError
                    ? throw $this->dataFailure : new OdometerReading(Distance::fromKilometres(20000), CarbonImmutable::parse('2026-10-24 12:00:00 UTC'));
            }
        };
    }

    private function app(): TeslaApp
    {
        $now = CarbonImmutable::parse('2026-10-24 12:00:00 UTC');

        return new TeslaApp(new User('driver@example.test', 'not-a-real-hash', $now), 'public', 'v1:private', $now);
    }
}
