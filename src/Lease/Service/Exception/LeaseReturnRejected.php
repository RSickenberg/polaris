<?php

declare(strict_types=1);

namespace Polaris\Lease\Service\Exception;

use Polaris\Lease\Service\Enum\LeaseReturnRejection;

/**
 * The car cannot be marked as returned on that day. Nothing was saved. The message is an English fallback;
 * callers translate {@see $reason}.
 */
final class LeaseReturnRejected extends \RuntimeException
{
    public function __construct(public readonly LeaseReturnRejection $reason)
    {
        parent::__construct(\sprintf('The return of the car was rejected: %s.', $reason->name));
    }
}
