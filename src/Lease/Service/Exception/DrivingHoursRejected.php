<?php

declare(strict_types=1);

namespace Polaris\Lease\Service\Exception;

use Polaris\Lease\Service\Enum\DrivingHoursRejection;

/**
 * The usual driving hours cannot be saved. Nothing was saved. The message is an English fallback; callers translate
 * {@see $reason}.
 */
final class DrivingHoursRejected extends \RuntimeException
{
    public function __construct(public readonly DrivingHoursRejection $reason)
    {
        parent::__construct(\sprintf('The driving hours were rejected: %s.', $reason->name));
    }
}
