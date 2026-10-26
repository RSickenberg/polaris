<?php

declare(strict_types=1);

namespace Polaris\Fetching\Domain;

/**
 * Where one scheduled slot of one vehicle stands.
 */
enum SlotRunStatus: string
{
    /**
     * The fetch is queued and has not run yet.
     */
    case Dispatched = 'dispatched';

    /**
     * The car was not online: the state will be checked again in an hour.
     */
    case Retrying = 'retrying';

    /**
     * A reading with a new value was stored.
     */
    case Stored = 'stored';

    /**
     * The car answered with the value already stored: nothing was stored.
     */
    case Unchanged = 'unchanged';

    /**
     * The car was not online for the whole window: the slot was skipped.
     */
    case Skipped = 'skipped';

    /**
     * The car answered with a value lower than a stored reading: nothing was stored.
     */
    case Rejected = 'rejected';

    /**
     * A newer slot came due, or a fetch read the car, before this one finished: its remaining checks are dropped.
     */
    case Superseded = 'superseded';

    /**
     * Polaris is not connected to Tesla (no token, expired, or no consent): nothing was asked.
     */
    case NotConnected = 'not_connected';

    /**
     * Whether checks are still queued for this slot.
     */
    public function isOpen(): bool
    {
        return self::Dispatched === $this || self::Retrying === $this;
    }
}
