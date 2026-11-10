<?php

declare(strict_types=1);

namespace Polaris\Lease\Service\Enum;

/**
 * Why the usual driving hours cannot be saved. The value is the key of the message in the `lease` translation domain.
 */
enum DrivingHoursRejection: string
{
    case NoContract = 'rejected.hours_no_contract';
    case Incomplete = 'rejected.hours_incomplete';
    case Invalid = 'rejected.hours_invalid';
    case SameTime = 'rejected.hours_same_time';
}
