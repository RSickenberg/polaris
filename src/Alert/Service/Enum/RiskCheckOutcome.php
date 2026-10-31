<?php

declare(strict_types=1);

namespace Polaris\Alert\Service\Enum;

enum RiskCheckOutcome
{
    /** No contract, no readings, or a lease that is a draft or has ended. */
    case NotAssessable;

    /** The level is the known one. */
    case Unchanged;

    /** The level was recorded without telling anybody. */
    case Recorded;

    /** The level got worse and the extension was told. */
    case Notified;

    /** Another check of the vehicle is running: try again later. */
    case Busy;
}
