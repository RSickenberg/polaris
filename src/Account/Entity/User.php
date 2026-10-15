<?php

declare(strict_types=1);

namespace Polaris\Account\Entity;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Polaris\Account\Domain\Initials;
use Polaris\Account\Repository\UserRepository;
use Polaris\Shared\Domain\TimeZone;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Ulid;

/**
 * The person who signs in. The table is named "app_user" because "user" is a reserved word
 * in PostgreSQL.
 *
 * The email is trimmed and lower-cased, and the password is only ever stored as a hash.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const string DEFAULT_LOCALE = 'en';
    public const int EMAIL_MAX_LENGTH = 180;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\Column(length: self::EMAIL_MAX_LENGTH, unique: true)]
    private string $email;

    /**
     * The password hash, never the password. The property is called "password" because
     * Symfony compares it, after {@see __serialize()}, to detect a changed password.
     */
    #[ORM\Column]
    private string $password;

    #[ORM\Column(length: 8)]
    private string $locale;

    #[ORM\Column(length: 64)]
    private string $timeZone;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private CarbonImmutable $createdAt;

    public function __construct(string $email, #[\SensitiveParameter] string $passwordHash, CarbonImmutable $createdAt)
    {
        $this->id = new Ulid();
        $this->email = self::normalizeEmail($email);
        $this->password = $passwordHash;
        $this->locale = self::DEFAULT_LOCALE;
        $this->timeZone = TimeZone::UTC;
        $this->createdAt = $createdAt->utc();
    }

    /**
     * @return non-empty-string
     */
    public static function normalizeEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));

        if ('' === $email || mb_strlen($email) > self::EMAIL_MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('An email must have 1 to %d characters.', self::EMAIL_MAX_LENGTH));
        }

        return $email;
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function changeEmail(string $email): void
    {
        $this->email = self::normalizeEmail($email);
    }

    /**
     * The letters of the avatar.
     */
    public function getInitials(): string
    {
        return Initials::fromEmail($this->email);
    }

    public function getUserIdentifier(): string
    {
        // Never empty: the constructor refuses an empty email.
        \assert('' !== $this->email);

        return $this->email;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function changePassword(#[\SensitiveParameter] string $passwordHash): void
    {
        $this->password = $passwordHash;
    }

    /**
     * The code of the preferred language. Whether it is an enabled one is the service's rule.
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    public function changeLocale(string $locale): void
    {
        if (1 !== preg_match('/^[a-z]{2,3}$/', $locale)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid locale code.', $locale));
        }

        $this->locale = $locale;
    }

    /**
     * An IANA time zone name, used only to display and enter dates (ADR 0003).
     */
    public function getTimeZone(): string
    {
        return $this->timeZone;
    }

    public function changeTimeZone(string $timeZone): void
    {
        if (!\in_array($timeZone, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an IANA time zone.', $timeZone));
        }

        $this->timeZone = $timeZone;
    }

    public function getCreatedAt(): CarbonImmutable
    {
        return $this->createdAt;
    }

    /**
     * Keeps the hash out of the session: only a checksum of it is stored.
     *
     * @return array<mixed>
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0" . self::class . "\0password"] = hash('crc32c', $this->password);

        return $data;
    }
}
