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

    /**
     * Whether a wake could bring the car online: it is asleep or offline. A car that stopped answering during the
     * live read ({@see self::Unavailable}) was online a moment before and is not woken.
     */
    public function isWakeable(): bool
    {
        return self::Asleep === $this || self::Offline === $this;
    }
}
