<?php

declare(strict_types=1);

namespace Polaris\Tesla\Entity;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Polaris\Account\Entity\User;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A Tesla developer app of a user (ADR 0004). For now it holds the key pair Tesla verifies; the
 * credentials come with the registration story.
 *
 * The public key is stored in clear. The private key is stored only as the ciphertext of
 * {@see \Polaris\Shared\Service\SecretEncrypter}.
 */
#[ORM\Entity]
class TeslaApp
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $owner;

    #[ORM\Column(type: Types::TEXT)]
    private string $publicKey;

    #[ORM\Column(type: Types::TEXT)]
    private string $encryptedPrivateKey;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private CarbonImmutable $keyGeneratedAt;

    public function __construct(User $owner, string $publicKey, string $encryptedPrivateKey, CarbonImmutable $generatedAt)
    {
        $this->id = new Ulid();
        $this->owner = $owner;
        $this->publicKey = $publicKey;
        $this->encryptedPrivateKey = $encryptedPrivateKey;
        $this->keyGeneratedAt = $generatedAt->utc();
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function getEncryptedPrivateKey(): string
    {
        return $this->encryptedPrivateKey;
    }

    public function getKeyGeneratedAt(): CarbonImmutable
    {
        return $this->keyGeneratedAt;
    }

    public function replaceKeyPair(string $publicKey, string $encryptedPrivateKey, CarbonImmutable $generatedAt): void
    {
        $this->publicKey = $publicKey;
        $this->encryptedPrivateKey = $encryptedPrivateKey;
        $this->keyGeneratedAt = $generatedAt->utc();
    }

    /**
     * Keeps the private key out of any serialization, even encrypted.
     *
     * @return array<mixed>
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        unset($data["\0" . self::class . "\0encryptedPrivateKey"]);

        return $data;
    }
}
