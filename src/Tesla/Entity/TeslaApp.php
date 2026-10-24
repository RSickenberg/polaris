<?php

declare(strict_types=1);

namespace Polaris\Tesla\Entity;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Polaris\Account\Entity\User;
use Polaris\Tesla\Domain\AppDomain;
use Polaris\Tesla\Domain\FleetRegion;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A Tesla developer app of a user (ADR 0004): the key pair Tesla verifies, the app credentials
 * (client ID, client secret, the domain that hosts the public key) and the Fleet API regions it
 * operates in. One app per user; the China region is a separate Tesla app and is not supported.
 *
 * The public key is stored in clear. The private key and the client secret are stored only as the
 * ciphertext of {@see \Polaris\Shared\Service\SecretEncrypter}.
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

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $clientId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $encryptedClientSecret = null;

    #[ORM\Column(length: 253, nullable: true)]
    private ?string $domain = null;

    /**
     * The codes of the enabled {@see FleetRegion} cases.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $enabledRegions = [];

    /**
     * When the user gave the opt-in consent to read their Tesla data (Fleet API Agreement, clause 9.3.7).
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?CarbonImmutable $consentGivenAt = null;

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

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function getEncryptedClientSecret(): ?string
    {
        return $this->encryptedClientSecret;
    }

    public function getDomain(): ?AppDomain
    {
        return null === $this->domain ? null : AppDomain::fromString($this->domain);
    }

    /**
     * @return list<FleetRegion>
     */
    public function getEnabledRegions(): array
    {
        return array_map(FleetRegion::from(...), $this->enabledRegions);
    }

    /**
     * @param list<FleetRegion> $regions
     */
    public function configure(string $clientId, string $encryptedClientSecret, AppDomain $domain, array $regions): void
    {
        $this->clientId = $clientId;
        $this->encryptedClientSecret = $encryptedClientSecret;
        $this->domain = $domain->toString();
        $this->enabledRegions = array_values(array_unique(array_map(
            static fn (FleetRegion $region): string => $region->value,
            $regions,
        )));
    }

    /**
     * Whether the app has everything the registration needs.
     */
    public function isConfigured(): bool
    {
        return null !== $this->clientId
            && null !== $this->encryptedClientSecret
            && null !== $this->domain
            && [] !== $this->enabledRegions;
    }

    public function giveConsent(CarbonImmutable $now): void
    {
        $this->consentGivenAt = $now->utc();
    }

    public function withdrawConsent(): void
    {
        $this->consentGivenAt = null;
    }

    public function getConsentGivenAt(): ?CarbonImmutable
    {
        return $this->consentGivenAt;
    }

    public function hasConsent(): bool
    {
        return null !== $this->consentGivenAt;
    }

    /**
     * Keeps the private key and the client secret out of any serialization, even encrypted.
     *
     * @return array<mixed>
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        unset(
            $data["\0" . self::class . "\0encryptedPrivateKey"],
            $data["\0" . self::class . "\0encryptedClientSecret"],
        );

        return $data;
    }
}
