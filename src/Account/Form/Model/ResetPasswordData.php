<?php

declare(strict_types=1);

namespace Polaris\Account\Form\Model;

use Polaris\Account\Service\NewUser;
use Symfony\Component\Validator\Constraints as Assert;

final class ResetPasswordData
{
    #[Assert\NotBlank]
    #[Assert\Length(min: NewUser::PASSWORD_MIN_LENGTH, max: NewUser::PASSWORD_MAX_LENGTH)]
    public string $newPassword = '';
}
