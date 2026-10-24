<?php

declare(strict_types=1);

namespace Polaris\Account\Form\Model;

use Polaris\Account\Service\Dto\NewUser;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A password change: the current password proves who asks, the new one is typed twice.
 */
final class PasswordChangeData
{
    #[UserPassword]
    public string $currentPassword = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: NewUser::PASSWORD_MIN_LENGTH, max: NewUser::PASSWORD_MAX_LENGTH)]
    public string $newPassword = '';
}
