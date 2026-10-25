<?php

declare(strict_types=1);

namespace Polaris\Reading\Service\Enum;

/**
 * What became of an odometer value read from the car.
 */
enum RecordOutcome: string
{
    /**
     * The value differs from the latest reading and was stored.
     */
    case Stored = 'stored';

    /**
     * The value equals the latest reading (or an existing one at the same instant): nothing was stored.
     */
    case Unchanged = 'unchanged';

    /**
     * The value breaks the order of the stored readings (an odometer never goes down): nothing was stored.
     */
    case Rejected = 'rejected';
}
