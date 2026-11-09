<?php

declare(strict_types=1);

namespace Polaris\Lease\Service\Enum;

/**
 * Why a car cannot be marked as returned on the given day. The value is the key of the message in the `lease`
 * translation domain.
 */
enum LeaseReturnRejection: string
{
    case NoContract = 'rejected.return_no_contract';
    case BeforeStart = 'rejected.return_before_start';
    case InFuture = 'rejected.return_in_future';
}
