<?php

declare(strict_types=1);

namespace Polaris\Account\Service\Enum;

/**
 * Why a settings change is refused. The value is the key of the message in the `validators` translation domain.
 */
enum UserSettingsRejection: string
{
    case EmailTaken = 'account.settings.email_taken';
}
