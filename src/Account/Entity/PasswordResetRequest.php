<?php

declare(strict_types=1);

namespace Polaris\Account\Entity;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Polaris\Account\Domain\ResetToken;
use Polaris\Account\Repository\PasswordResetRequestRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A pending password reset: valid for an hour, and gone once used. Only a hash of the secret
 * is stored (see {@see ResetToken}).
 */
#[ORM\Entity(repositoryClass: PasswordResetRequestRepository::class)]
class PasswordResetRequest
{
    public const string LIFETIME = '+1 hour';

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20, unique: true)]
    private string $selector;

    #[ORM\Column(length: 64)]
    private string $hashedVerifier;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private CarbonImmutable $requestedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private CarbonImmutable $expiresAt;

    public function __construct(User $user, ResetToken $token, CarbonImmutable $requestedAt)
    {
        $this->id = new Ulid();
        $this->user = $user;
        $this->selector = $token->selector();
        $this->hashedVerifier = $token->hashedVerifier();
        $this->requestedAt = $requestedAt->utc();
        $this->expiresAt = $requestedAt->utc()->modify(self::LIFETIME);
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRequestedAt(): CarbonImmutable
    {
        return $this->requestedAt;
    }

    public function getExpiresAt(): CarbonImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(CarbonImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function isFor(ResetToken $token): bool
    {
        return $token->matches($this->hashedVerifier);
    }
}
