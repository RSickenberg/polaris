<?php

declare(strict_types=1);

namespace Polaris\Fetching\Domain;

/**
 * How one manual refresh ended. The fetch is asynchronous, so a refresh starts as {@see self::Queued}
 * and the worker settles it.
 */
enum RefreshOutcome: string
{
    /**
     * The fetch is queued and has not run yet.
     */
    case Queued = 'queued';

    /**
     * A reading with a new value was stored.
     */
    case Stored = 'stored';

    /**
     * The car answered with the value already stored: nothing was stored.
     */
    case Unchanged = 'unchanged';

    /**
     * The car is asleep: it was not woken and the last reading stays.
     */
    case Asleep = 'asleep';

    /**
     * The car is offline: the last reading stays.
     */
    case Offline = 'offline';

    /**
     * The car was online but fell asleep or went offline before the live read.
     */
    case Unavailable = 'unavailable';

    /**
     * Polaris is not connected to Tesla: nothing was asked.
     */
    case NotConnected = 'not_connected';

    /**
     * The car answered with a value lower than a stored reading: nothing was stored.
     */
    case Rejected = 'rejected';

    /**
     * Another fetch of the vehicle was running: nothing was asked.
     */
    case Busy = 'busy';

    /**
     * Tesla answered with an error: the message went to the failure transport.
     */
    case Failed = 'failed';

    /**
     * How to tell the user: a new reading is good news, a car that is not reachable is information, and what
     * needs an action of theirs or went wrong is an error.
     *
     * @return 'success'|'info'|'error'
     */
    public function toastVariant(): string
    {
        return match ($this) {
            self::Stored => 'success',
            self::Queued, self::Unchanged, self::Asleep, self::Offline, self::Unavailable, self::Busy => 'info',
            self::NotConnected, self::Rejected, self::Failed => 'error',
        };
    }

    public function isSettled(): bool
    {
        return self::Queued !== $this;
    }
}
