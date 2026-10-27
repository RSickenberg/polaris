<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Enum;

/**
 * Whether the stored connection of a Tesla app may wake a vehicle.
 */
enum WakeAccess: string
{
    case Allowed = 'allowed';

    /**
     * There is no usable connection to Tesla.
     */
    case NotConnected = 'not_connected';

    /**
     * Connected, but the `vehicle_cmds` scope was never granted: the user must go through Tesla again.
     */
    case ScopeMissing = 'scope_missing';
}
