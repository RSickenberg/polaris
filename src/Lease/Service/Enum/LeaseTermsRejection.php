<?php

declare(strict_types=1);

namespace Polaris\Lease\Service\Enum;

/**
 * Why new lease terms are refused. The value is the key of the message in the `lease` translation domain.
 */
enum LeaseTermsRejection: string
{
    /**
     * The terms contradict the recorded readings, for example a start odometer above a reading.
     */
    case InconsistentReadings = 'rejected.inconsistent_readings';

    /**
     * The car was marked as returned, and the new start is on or after that instant.
     */
    case StartAfterReturn = 'rejected.start_after_return';
}
