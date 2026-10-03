<?php

declare(strict_types=1);

namespace Polaris\Lease\Service;

/**
 * The new lease terms cannot be projected over the readings already recorded, for example a
 * start odometer above a recorded reading. Nothing was saved. The message is the translation
 * key (domain "lease") the caller shows.
 */
final class LeaseTermsRejected extends \RuntimeException
{
    public const string INCONSISTENT_READINGS = 'rejected.inconsistent_readings';

    public function __construct(string $key = self::INCONSISTENT_READINGS, ?\Throwable $previous = null)
    {
        parent::__construct($key, 0, $previous);
    }
}
