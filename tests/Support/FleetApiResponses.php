<?php

declare(strict_types=1);

namespace Polaris\Tests\Support;

/**
 * Fleet API answers for the tests. The list, the vehicle state and the 408 are real answers of an EU
 * account (ids, VIN and tokens removed, VIN replaced by a fake one). The live vehicle_data answer was
 * never seen with the car awake: it is built from the documented layout and is UNVERIFIED.
 */
final class FleetApiResponses
{
    public const string VIN = 'LRW0000000000TEST';
    public const string OTHER_VIN = 'LRW0000000001TEST';

    public static function vehicleList(): string
    {
        return (string) json_encode([
            'response' => [
                [
                    'id' => 1,
                    'vehicle_id' => 2,
                    'vin' => self::VIN,
                    'color' => null,
                    'display_name' => 'Lucia',
                    'option_codes' => null,
                    'state' => 'offline',
                    'in_service' => false,
                    'api_version' => 95,
                ],
                [
                    'id' => 3,
                    'vehicle_id' => 4,
                    'vin' => self::OTHER_VIN,
                    'color' => null,
                    'display_name' => 'Raven',
                    'option_codes' => null,
                    'state' => 'online',
                    'in_service' => false,
                    'api_version' => 95,
                    'share_type_s' => 'ADD_DRIVER_EXTERNAL',
                ],
            ],
            'pagination' => ['previous' => null, 'next' => null, 'current' => 1, 'per_page' => 2, 'count' => 2, 'pages' => 1],
            'count' => 2,
        ]);
    }

    public static function vehicle(string $state): string
    {
        return (string) json_encode([
            'response' => [
                'id' => 1,
                'vehicle_id' => 2,
                'vin' => self::VIN,
                'color' => null,
                'display_name' => 'Lucia',
                'option_codes' => null,
                'state' => $state,
                'in_service' => false,
                'api_version' => 95,
            ],
        ]);
    }

    /**
     * UNVERIFIED: the odometer in miles under vehicle_state, as assumed by the client.
     */
    public static function vehicleData(float $miles): string
    {
        return (string) json_encode([
            'response' => [
                'charge_state' => ['battery_level' => 76],
                'vehicle_state' => ['vehicle_name' => 'Lucia', 'odometer' => $miles, 'locked' => true],
            ],
        ]);
    }

    public static function vehicleNotOnline(): string
    {
        return (string) json_encode([
            'response' => null,
            'error' => 'vehicle unavailable: vehicle is offline or asleep',
            'error_description' => '',
            'txid' => '16728378bd46960e3e40f5d7ddc36ee1',
        ]);
    }
}
