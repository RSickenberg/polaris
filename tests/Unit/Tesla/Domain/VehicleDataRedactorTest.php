<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Tesla\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Tesla\Domain\VehicleDataRedactor;

#[CoversClass(VehicleDataRedactor::class)]
final class VehicleDataRedactorTest extends TestCase
{
    public function testLocationKeysAreRemovedAtEveryDepthAndTheRestIsKept(): void
    {
        $data = [
            'state' => 'online',
            'drive_state' => ['latitude' => 12.3456, 'longitude' => 65.4321, 'heading' => 90, 'speed' => null, 'shift_state' => null],
            'nested' => ['deeper' => ['native_latitude' => 1.0, 'native_longitude' => 2.0, 'gps_as_of' => 1792864425, 'kept' => true]],
            'location_data' => ['anything' => 1],
            'vehicle_state' => ['odometer' => 100.5, 'locked' => true],
            'list' => [['latitude' => 1.0, 'name' => 'a'], ['name' => 'b']],
        ];

        self::assertSame(
            [
                'state' => 'online',
                'drive_state' => ['speed' => null, 'shift_state' => null],
                'nested' => ['deeper' => ['kept' => true]],
                'vehicle_state' => ['odometer' => 100.5, 'locked' => true],
                'list' => [['name' => 'a'], ['name' => 'b']],
            ],
            VehicleDataRedactor::withoutLocation($data),
        );
    }

    public function testAnAnswerWithoutLocationIsReturnedAsItIs(): void
    {
        $data = ['charge_state' => ['battery_level' => 76], 'list' => [1, 2, 3]];

        self::assertSame($data, VehicleDataRedactor::withoutLocation($data));
    }
}
