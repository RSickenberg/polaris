<?php

declare(strict_types=1);

namespace Polaris\Tesla\Domain;

/**
 * Takes the location out of a `vehicle_data` answer before it is kept. Polaris never asks for the location
 * (no `vehicle_location` scope, no `location_data` endpoint), but an answer can still carry coordinates in
 * `drive_state`, and nothing of that must reach the database.
 */
final class VehicleDataRedactor
{
    private const array LOCATION_KEYS = [
        'latitude',
        'longitude',
        'native_latitude',
        'native_longitude',
        'heading',
        'gps_as_of',
        'location_data',
    ];

    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed> the same answer, at every depth, without the location keys
     */
    public static function withoutLocation(array $data): array
    {
        $kept = [];

        foreach ($data as $key => $value) {
            if (\is_string($key) && \in_array($key, self::LOCATION_KEYS, true)) {
                continue;
            }

            $kept[$key] = \is_array($value) ? self::withoutLocation($value) : $value;
        }

        return $kept;
    }
}
