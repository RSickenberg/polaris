<?php

declare(strict_types=1);

namespace Polaris\Reading\Domain;

/**
 * Why a new odometer reading is refused. The value is the translation key of the message.
 */
enum ReadingRejection: string
{
    case LowerThanPrevious = 'reading.rejected.lower_than_previous';
    case HigherThanNext = 'reading.rejected.higher_than_next';
    case DuplicateInstant = 'reading.rejected.duplicate_instant';
    case InFuture = 'reading.rejected.in_future';
}
