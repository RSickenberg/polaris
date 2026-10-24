<?php

declare(strict_types=1);

namespace Polaris\Tesla\Domain;

/**
 * The OAuth scopes Polaris requests (ADR 0004). `vehicle_cmds` is not requested until the wake story ships.
 */
final class TeslaScopes
{
    public const string REQUESTED = 'openid offline_access vehicle_device_data';

    private function __construct()
    {
    }
}
