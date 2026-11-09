<?php

declare(strict_types=1);

namespace Polaris\Lease\Service\Exception;

use Polaris\Lease\Service\Enum\LeaseTermsRejection;

/**
 * The new lease terms cannot be projected over the readings already recorded. Nothing was saved. The message is
 * an English fallback; callers translate {@see $reason}.
 */
final class LeaseTermsRejected extends \RuntimeException
{
    public function __construct(
        public readonly LeaseTermsRejection $reason = LeaseTermsRejection::InconsistentReadings,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(\sprintf('The lease terms were rejected: %s.', $reason->name), 0, $previous);
    }
}
