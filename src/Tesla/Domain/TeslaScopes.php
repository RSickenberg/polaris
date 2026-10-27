<?php

declare(strict_types=1);

namespace Polaris\Tesla\Domain;

/**
 * The OAuth scopes Polaris requests (ADR 0004). `vehicle_cmds` is there only for the wake_up call of a
 * manual refresh the user confirmed; `vehicle_location` is never requested.
 */
final class TeslaScopes
{
    public const string REQUESTED = 'openid offline_access vehicle_device_data vehicle_cmds';

    public const string WAKE = 'vehicle_cmds';

    private function __construct()
    {
    }

    /**
     * Whether a space-delimited list of granted scopes allows waking a vehicle.
     */
    public static function allowsWake(string $granted): bool
    {
        return \in_array(self::WAKE, preg_split('/\s+/', trim($granted)) ?: [], true);
    }
}
