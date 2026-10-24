<?php

declare(strict_types=1);

namespace Polaris\Account\Service\Exception;

/**
 * A settings change that the rules refuse.
 */
final class UserSettingsRejected extends \RuntimeException
{
    public static function emailTaken(): self
    {
        return new self('account.settings.email_taken');
    }
}
