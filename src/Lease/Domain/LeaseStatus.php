<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

enum LeaseStatus: string
{
    /** The lease end has not been reached yet. */
    case Active = 'active';

    /**
     * The lease end has passed: no time is left, so the budgets are zero and the excess
     * keeps growing with every kilometre driven until the car is returned.
     */
    case Ended = 'ended';
}
