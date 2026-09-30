<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

enum LeaseStatus: string
{
    /**
     * The lease is not final yet. Nothing in the assessment produces or depends on this
     * status yet: it only exists so that callers can model it.
     */
    case Draft = 'draft';

    /** The lease end has not been reached yet. */
    case Active = 'active';

    /**
     * The lease end has passed: no time is left, so the budgets are zero and the excess
     * keeps growing with every kilometre driven until the car is returned.
     */
    case Ended = 'ended';
}
