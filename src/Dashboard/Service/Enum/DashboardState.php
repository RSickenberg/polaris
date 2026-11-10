<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Service\Enum;

use Polaris\Lease\Domain\LeaseStatus;

/**
 * What the dashboard of a vehicle can show.
 */
enum DashboardState: string
{
    /** The vehicle has no lease contract. */
    case NoLease = 'no_lease';

    /** The contract has no odometer reading after the lease start. */
    case NoReading = 'no_reading';

    /** The lease has not started: nothing is assessed yet. */
    case Draft = 'draft';

    /** The lease is running: figures, projection and budgets. */
    case Active = 'active';

    /** The lease end has passed: figures so far, no budget. */
    case Ended = 'ended';

    /** The user gave the car back: final figures at the last reading up to the return, no forecast, no refresh. */
    case Returned = 'returned';

    /**
     * The state of an assessed lease: the one place that maps a lease status to what the pages show.
     */
    public static function forStatus(LeaseStatus $status): self
    {
        return match ($status) {
            LeaseStatus::Draft => self::Draft,
            LeaseStatus::Active => self::Active,
            LeaseStatus::Ended => self::Ended,
            LeaseStatus::Returned => self::Returned,
        };
    }

    /**
     * Whether the figures are final or as of the lease end: no forecast, no budget.
     */
    public function hasEnded(): bool
    {
        return self::Ended === $this || self::Returned === $this;
    }

    /**
     * Whether the odometer can be refreshed from the dashboard: only while the lease runs.
     */
    public function canRefresh(): bool
    {
        return self::Active === $this;
    }
}
