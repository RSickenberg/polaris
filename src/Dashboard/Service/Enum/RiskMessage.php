<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Service\Enum;

/**
 * The sentence under the risk level of the dashboard. The value is its key in the `dashboard` translation domain;
 * every message takes the same parameters (`excess`, `cost`, `cut`, `projected`, `allowance`, `unit`) and uses the
 * ones it needs.
 */
enum RiskMessage: string
{
    /** The lease runs and the projection stays within the allowance. */
    case Ok = 'risk.message.ok';

    /** The lease runs and the projection is within the tolerance below the allowance. */
    case Watch = 'risk.message.watch';

    /** The lease runs, the projection is over the allowance, and driving less per week would fix it. */
    case Over = 'risk.message.over';

    /** The lease runs and the projection is over the allowance, with no weekly cut to suggest. */
    case OverWithoutCut = 'risk.message.over_no_cut';

    /** The lease has ended within the allowance. */
    case EndedWithin = 'risk.message.ended_ok';

    /** The lease has ended over the allowance. */
    case EndedOver = 'risk.message.ended_over';

    /** The car was handed back within the allowance. */
    case ReturnedWithin = 'risk.message.returned_ok';

    /** The car was handed back over the allowance. */
    case ReturnedOver = 'risk.message.returned_over';
}
