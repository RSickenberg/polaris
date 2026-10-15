<?php

declare(strict_types=1);

namespace Polaris\Account\Form\Model;

use Polaris\Account\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

final class ForgotPasswordData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: User::EMAIL_MAX_LENGTH)]
    #[Assert\Email]
    public string $email = '';
}
