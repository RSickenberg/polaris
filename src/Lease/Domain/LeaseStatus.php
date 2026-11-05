<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

enum LeaseStatus: string
{
    /**
     * The user is about to create the contract but is still waiting for the car, for
     * example a Tesla on order. Nothing in the assessment produces this status yet: a
     * contract has no draft flag, so callers set it.
     */
    case Draft = 'draft';

    /** The lease end has not been reached yet. */
    case Active = 'active';

    /**
     * The lease end has passed: no time is left, so the budgets are zero and the excess
     * keeps growing with every kilometre driven until the user says the car was returned
     * ({@see self::Returned}).
     */
    case Ended = 'ended';

    /**
     * The user gave the car back: the figures are final, taken at the last reading before or at
     * the return, whatever the clock says. No budget, no weekly cut, no forecast.
     */
    case Returned = 'returned';
}
