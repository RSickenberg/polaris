<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Enum;

/**
 * Why an odometer read gave no value. None of them is an error: the car is simply not reachable
 * without waking it, which Polaris never does here.
 */
enum NoReadingReason: string
{
    case Asleep = 'asleep';
    case Offline = 'offline';

    /**
     * The car was reported online, but fell asleep or went offline before the live read (HTTP 408).
     */
    case Unavailable = 'unavailable';
}
