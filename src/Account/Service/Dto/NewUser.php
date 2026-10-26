<?php

declare(strict_types=1);

namespace Polaris\Account\Service\Dto;

use Polaris\Account\Entity\User;
use Polaris\Shared\Domain\TimeZone;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What it takes to create the user: the plain password lives only as long as this object.
 * Without a locale, the user gets the default one.
 */
final readonly class NewUser
{
    /**
     * Length rather than composition rules: a long passphrase is stronger and easier to remember.
     * 4096 is the longest password Symfony hashes.
     */
    public const int PASSWORD_MIN_LENGTH = 12;
    public const int PASSWORD_MAX_LENGTH = 4096;

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: User::EMAIL_MAX_LENGTH)]
        #[Assert\Email]
        public string $email,

        #[\SensitiveParameter]
        #[Assert\NotBlank]
        #[Assert\Length(min: self::PASSWORD_MIN_LENGTH, max: self::PASSWORD_MAX_LENGTH)]
        public string $plainPassword,

        public ?string $locale = null,

        #[Assert\Choice(callback: [\DateTimeZone::class, 'listIdentifiers'])]
        public string $timeZone = TimeZone::UTC,
    ) {
    }
}
