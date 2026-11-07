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
        $vehicles = $this->vehicles(VehicleState::Online, 1);

        $result = new TeslaOdometerReader($vehicles)->read($this->app(), new Vin(FleetApiResponses::VIN));

        self::assertTrue($result->hasReading());
        self::assertSame(20_000_000, $result->reading?->odometer->metres());
        self::assertNull($result->reason);
    }

    public function testAnAsleepCarGivesNoReadingAndIsNotAsked(): void
    {
        $vehicles = $this->vehicles(VehicleState::Asleep, 0);

        $result = new TeslaOdometerReader($vehicles)->read($this->app(), new Vin(FleetApiResponses::VIN));

        self::assertFalse($result->hasReading());
        self::assertSame(NoReadingReason::Asleep, $result->reason);
    }

    public function testAnOfflineCarGivesNoReadingAndIsNotAsked(): void
    {
        $vehicles = $this->vehicles(VehicleState::Offline, 0);

        $result = new TeslaOdometerReader($vehicles)->read($this->app(), new Vin(FleetApiResponses::VIN));

        self::assertSame(NoReadingReason::Offline, $result->reason);
    }

    public function testACarThatFellAsleepBetweenTheCallsGivesNoReadingAndIsAskedOnce(): void
    {
        $vehicles = $this->vehicles(VehicleState::Online, 1, FleetApiError::fromResponse(FleetApiOperation::VehicleData, FleetRegion::EuropeMiddleEastAfrica, 408, FleetApiResponses::vehicleNotOnline()));

        $result = new TeslaOdometerReader($vehicles)->read($this->app(), new Vin(FleetApiResponses::VIN));

        self::assertSame(NoReadingReason::Unavailable, $result->reason);
    }

    public function testOtherFailuresOfTheLiveCallAreNotHidden(): void
    {
        $vehicles = $this->vehicles(VehicleState::Online, 1, FleetApiError::fromResponse(FleetApiOperation::VehicleData, FleetRegion::EuropeMiddleEastAfrica, 503, ''));

        $this->expectException(FleetApiError::class);

        new TeslaOdometerReader($vehicles)->read($this->app(), new Vin(FleetApiResponses::VIN));
    }

    /**
     * @param int $dataCalls how many live reads the reader must make (it must not ask a car that is not online)
     */
    private function vehicles(VehicleState $state, int $dataCalls, ?FleetApiError $dataFailure = null): VehicleClient
    {
        $client = $this->createMock(VehicleClient::class);
        $client->method('vehicleState')->willReturn($state);
        $data = $client->expects(self::exactly($dataCalls))->method('vehicleData');

        if ($dataFailure instanceof FleetApiError) {
            $data->willThrowException($dataFailure);
        } else {
            $data->willReturn(new OdometerReading(Distance::fromKilometres(20000), CarbonImmutable::parse('2026-10-24 12:00:00 UTC')));
        }

        return $client;
    }

    private function app(): TeslaApp
    {
        $now = CarbonImmutable::parse('2026-10-24 12:00:00 UTC');

        return new TeslaApp(new User('driver@example.test', 'not-a-real-hash', $now), 'public', 'v1:private', $now);
    }
}
