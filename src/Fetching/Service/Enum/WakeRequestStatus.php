<?php

declare(strict_types=1);

namespace Polaris\Fetching\Service\Enum;

/**
 * What a confirmed wake got: a later API maps {@see self::Accepted} to 202 and {@see self::CapReached} to 429.
 */
enum WakeRequestStatus: string
{
    case Accepted = 'accepted';

    /**
     * The vehicle was already woken as often as the daily cap allows.
     */
    case CapReached = 'cap_reached';

    /**
     * The hourly limit of the manual refresh is spent: the read that follows a wake would be refused.
     */
    case RateLimited = 'rate_limited';

    /**
     * The `vehicle_cmds` scope was never granted: the user must connect to Tesla again.
     */
    case ScopeMissing = 'scope_missing';

    case NotConnected = 'not_connected';

    /**
     * The refresh did not end with a sleeping car, or ended too long ago, or a wake is already running.
     */
    case NothingToWake = 'nothing_to_wake';
}
