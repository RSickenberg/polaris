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
     * The car is online but the hourly limit of the manual refresh was spent in the meantime (another refresh
     * read it first): it was not read.
     */
    case Limited = 'limited';

    /**
     * Tesla answered with an error: the message went to the failure transport.
     */
    case Failed = 'failed';

    /**
     * The user confirmed a wake and wake_up was sent: the state of the car is polled until it is online or the
     * time is up.
     */
    case Waking = 'waking';

    /**
     * Tesla could not reach the car to send the wake (HTTP 408): nothing was counted and the last reading stays.
     */
    case WakeFailed = 'wake_failed';

    /**
     * The car did not come online in time after a wake: the last reading stays.
     */
    case WakeTimeout = 'wake_timeout';

    /**
     * No wake was sent: the user already woke the vehicle as often as the cap allows.
     */
    case WakeCapReached = 'wake_cap';

    /**
     * No wake was sent, or Tesla refused it: the `vehicle_cmds` scope is missing or was revoked.
     */
    case WakeScopeMissing = 'wake_scope';

    /**
     * How to tell the user: a new reading is good news, a car that is not reachable is a warning, and what
     * needs an action of theirs or went wrong is danger.
     *
     * @return 'success'|'warning'|'info'|'danger'
     */
    public function toastVariant(): string
    {
        return match ($this) {
            self::Stored => 'success',
            self::Queued, self::Waking, self::Unchanged => 'info',
            self::Asleep, self::Offline, self::Unavailable, self::Busy, self::Limited, self::WakeTimeout, self::WakeFailed, self::WakeCapReached => 'warning',
            self::NotConnected, self::Rejected, self::Failed, self::WakeScopeMissing => 'danger',
        };
    }

    public function isSettled(): bool
    {
        return self::Queued !== $this && self::Waking !== $this;
    }
}
