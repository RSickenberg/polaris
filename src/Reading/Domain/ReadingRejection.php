<?php

declare(strict_types=1);

namespace Polaris\Reading\Domain;

/**
 * Why a new odometer reading is refused. The value is the key of the message in the `reading` domain.
 */
enum ReadingRejection: string
{
    case LowerThanPrevious = 'rejected.lower_than_previous';
    case HigherThanNext = 'rejected.higher_than_next';
    case DuplicateInstant = 'rejected.duplicate_instant';
    case InFuture = 'rejected.in_future';
}
