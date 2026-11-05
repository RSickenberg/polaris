<?php

declare(strict_types=1);

namespace Polaris\Lease\Service\Exception;

/**
 * The car cannot be marked as returned on that date. Nothing was saved. The message is the
 * translation key (domain "lease") the caller shows.
 */
final class LeaseReturnRejected extends \RuntimeException
{
    public const string NO_CONTRACT = 'rejected.return_no_contract';
    public const string BEFORE_START = 'rejected.return_before_start';
    public const string IN_FUTURE = 'rejected.return_in_future';

    public function __construct(string $key)
    {
        parent::__construct($key);
    }
}
