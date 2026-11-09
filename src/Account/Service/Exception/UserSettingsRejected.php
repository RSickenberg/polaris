<?php

declare(strict_types=1);

namespace Polaris\Account\Service\Exception;

use Polaris\Account\Service\Enum\UserSettingsRejection;

/**
 * A settings change that the rules refuse; nothing was saved. The message is an English fallback; callers
 * translate {@see $reason}.
 */
final class UserSettingsRejected extends \RuntimeException
{
    public function __construct(public readonly UserSettingsRejection $reason)
    {
        parent::__construct(\sprintf('The settings change was rejected: %s.', $reason->name));
    }
}
