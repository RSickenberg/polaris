<?php

declare(strict_types=1);

namespace Polaris\Account\Form\Model;

use Polaris\Account\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The profile part of the settings: who the user is and how dates and texts are shown.
 */
final class ProfileData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: User::EMAIL_MAX_LENGTH)]
    #[Assert\Email]
    public string $email = '';

    #[Assert\NotBlank]
    public string $locale = User::DEFAULT_LOCALE;

    #[Assert\NotBlank]
    public string $timeZone = 'UTC';

    public static function fromUser(User $user): self
    {
        $data = new self();
        $data->email = $user->getEmail();
        $data->locale = $user->getLocale();
        $data->timeZone = $user->getTimeZone();

        return $data;
    }
}
