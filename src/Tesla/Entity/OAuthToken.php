<?php

declare(strict_types=1);

namespace Polaris\Tesla\Entity;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Polaris\Tesla\Domain\ConnectionMethod;
use Polaris\Tesla\Domain\ConnectionStatus;
use Polaris\Tesla\Domain\TeslaScopes;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * The connection of a {@see TeslaApp} to the Tesla account of its owner: the access token and the
 * single-use refresh token, both stored only as {@see \Polaris\Shared\Service\SecretEncrypter}
 * ciphertext, and when the access token expires. One row per app, kept when Tesla refuses the refresh
 * token (status Expired) so the interface can say the connection must be made again.
 */
#[ORM\Entity]
class OAuthToken
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(unique: true, nullable: false, onDelete: 'CASCADE')]
    private TeslaApp $teslaApp;

    #[ORM\Column(type: Types::TEXT)]
    private string $encryptedAccessToken;

    #[ORM\Column(type: Types::TEXT)]
    private string $encryptedRefreshToken;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private CarbonImmutable $expiresAt;

    /**
     * Space-delimited scopes Tesla granted.
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $scopes;

    #[ORM\Column(length: 16, enumType: ConnectionStatus::class)]
    private ConnectionStatus $status = ConnectionStatus::Connected;

    #[ORM\Column(length: 32, enumType: ConnectionMethod::class)]
    private ConnectionMethod $method;

    /**
     * The Fleet API base URL of the user's region (GET /api/1/users/region).
     */
    #[ORM\Column(length: 255)]
    private string $fleetApiBaseUrl;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private CarbonImmutable $connectedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private CarbonImmutable $refreshedAt;

    public function __construct(
        TeslaApp $teslaApp,
        string $encryptedAccessToken,
        string $encryptedRefreshToken,
        CarbonImmutable $expiresAt,
        string $scopes,
        ConnectionMethod $method,
        string $fleetApiBaseUrl,
        CarbonImmutable $now,
    ) {
        $this->id = new Ulid();
        $this->teslaApp = $teslaApp;
        $this->encryptedAccessToken = $encryptedAccessToken;
        $this->encryptedRefreshToken = $encryptedRefreshToken;
        $this->expiresAt = $expiresAt->utc();
        $this->scopes = $scopes;
        $this->method = $method;
        $this->fleetApiBaseUrl = $fleetApiBaseUrl;
        $this->connectedAt = $now->utc();
        $this->refreshedAt = $now->utc();
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getTeslaApp(): TeslaApp
    {
        return $this->teslaApp;
    }

    public function getEncryptedAccessToken(): string
    {
        return $this->encryptedAccessToken;
    }

    public function getEncryptedRefreshToken(): string
    {
        return $this->encryptedRefreshToken;
    }

    public function getExpiresAt(): CarbonImmutable
    {
        return $this->expiresAt;
    }

    public function getScopes(): string
    {
        return $this->scopes;
    }

    public function getStatus(): ConnectionStatus
    {
        return $this->status;
    }

    public function isConnected(): bool
    {
        return ConnectionStatus::Connected === $this->status;
    }

    /**
     * Whether the user granted the scope that wake_up needs. A connection made before the wake existed lacks it.
     */
    public function canWake(): bool
    {
        return TeslaScopes::allowsWake($this->scopes);
    }

    public function getMethod(): ConnectionMethod
    {
        return $this->method;
    }

    public function getFleetApiBaseUrl(): string
    {
        return $this->fleetApiBaseUrl;
    }

    public function getConnectedAt(): CarbonImmutable
    {
        return $this->connectedAt;
    }

    public function getRefreshedAt(): CarbonImmutable
    {
        return $this->refreshedAt;
    }

    /**
     * Whether the access token is expired or about to be, given the safety margin in seconds.
     */
    public function needsRefresh(CarbonImmutable $now, int $marginSeconds): bool
    {
        return $this->expiresAt->subSeconds($marginSeconds)->lessThanOrEqualTo($now);
    }

    /**
     * Replaces both tokens after a refresh. The caller flushes in the same transaction, before the
     * new access token is used, because the previous refresh token is now spent.
     */
    public function rotate(
        string $encryptedAccessToken,
        string $encryptedRefreshToken,
        CarbonImmutable $expiresAt,
        string $scopes,
        CarbonImmutable $now,
    ): void {
        $this->encryptedAccessToken = $encryptedAccessToken;
        $this->encryptedRefreshToken = $encryptedRefreshToken;
        $this->expiresAt = $expiresAt->utc();
        $this->scopes = $scopes;
        $this->refreshedAt = $now->utc();
        $this->status = ConnectionStatus::Connected;
    }

    public function markExpired(): void
    {
        $this->status = ConnectionStatus::Expired;
    }

    /**
     * Keeps both ciphertexts out of any serialization.
     *
     * @return array<mixed>
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        unset(
            $data["\0" . self::class . "\0encryptedAccessToken"],
            $data["\0" . self::class . "\0encryptedRefreshToken"],
        );

        return $data;
    }
}
