<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Service;

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
}
