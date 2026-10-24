<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Shared\Service\DecryptionFailed;
use Polaris\Shared\Service\SecretEncrypter;
use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Entity\OAuthToken;
use Polaris\Tesla\Entity\TeslaApp;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\LockFactory;

/**
 * Refreshes the access token shortly before it expires. A Tesla refresh token is single use, so two
 * processes must never refresh at once: the refresh runs under a lock per Tesla app, the token is read
 * again from the database once the lock is held (another process may have refreshed meanwhile, and its
 * answer is then reused), and the new refresh token is committed in a transaction before the new access
 * token is handed out.
 *
 * Tesla refusing the refresh token marks the connection as expired and keeps the row; nothing is
 * retried. Any other failure leaves the stored tokens as they were.
 */
final readonly class LockedTokenProvider implements TokenProvider
{
    private const float LOCK_TTL_SECONDS = 60.0;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private OAuthClient $client,
        private SecretEncrypter $encrypter,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
        #[Autowire(env: 'int:POLARIS_TESLA_REFRESH_MARGIN')]
        private int $refreshMarginSeconds,
    ) {
    }

    /**
     * @throws TeslaNotConnected       when there is no connection, or Tesla refused the refresh token
     * @throws FleetApiError           when Tesla could not be reached: the stored tokens are untouched
     * @throws DecryptionFailed        when a stored token cannot be decrypted (wrong key or altered value)
     * @throws LockAcquiringException  when the refresh lock cannot be taken
     * @throws LockConflictedException when the refresh lock is held by someone else
     * @throws LockReleasingException  when the refresh lock cannot be released
     * @throws ORMException            when the stored row cannot be read again or the rotation cannot be committed
     * @throws \Random\RandomException
     * @throws \SodiumException
     */
    public function accessToken(TeslaApp $app, bool $force = false): string
    {
        $token = $this->find($app);

        if (!$token->isConnected()) {
            throw TeslaNotConnected::expired();
        }

        // Fast path: no lock, the stored token is still good.
        if (null !== $usable = $this->usableAccessToken($token, $force)) {
            return $usable;
        }

        $lock = $this->lockFactory->createLock('tesla_oauth_refresh_' . $app->getId()->toBase32(), self::LOCK_TTL_SECONDS);
        $lock->acquire(true);

        try {
            // The row is read again: another process may have refreshed while this one waited for the lock.
            $this->entityManager->refresh($token);

            if (!$token->isConnected()) {
                throw TeslaNotConnected::expired();
            }

            // Another process may have refreshed while this one waited: reuse its token.
            if (null !== $usable = $this->usableAccessToken($token, $force)) {
                return $usable;
            }

            return $this->refresh($app, $token);
        } finally {
            $lock->release();
        }
    }

    /**
     * The stored access token when it needs no refresh (and a refresh is not forced), else null.
     *
     * @throws DecryptionFailed
     * @throws \SodiumException
     */
    private function usableAccessToken(OAuthToken $token, bool $force): ?string
    {
        if ($force || $token->needsRefresh($this->now(), $this->refreshMarginSeconds)) {
            return null;
        }

        return $this->encrypter->decrypt($token->getEncryptedAccessToken());
    }

    private function find(TeslaApp $app): OAuthToken
    {
        $token = $this->entityManager->getRepository(OAuthToken::class)->findOneBy(['teslaApp' => $app]);

        return $token instanceof OAuthToken ? $token : throw TeslaNotConnected::never();
    }

    /**
     * @throws TeslaNotConnected
     * @throws FleetApiError
     * @throws DecryptionFailed
     * @throws \Random\RandomException
     * @throws \SodiumException
     */
    private function refresh(TeslaApp $app, OAuthToken $token): string
    {
        $region = FleetRegion::fromBaseUrl($token->getFleetApiBaseUrl()) ?? $this->fallbackRegion($app);

        try {
            $tokens = $this->client->refresh(
                $region,
                (string) $app->getClientId(),
                $this->encrypter->decrypt($token->getEncryptedRefreshToken()),
            );
        } catch (FleetApiError $failure) {
            if ($failure->refreshTokenRejected()) {
                $token->markExpired();
                $this->entityManager->flush();

                throw TeslaNotConnected::expired();
            }

            throw $failure;
        }

        $now = $this->now();
        $encryptedAccess = $this->encrypter->encrypt($tokens->accessToken);
        $encryptedRefresh = $this->encrypter->encrypt($tokens->refreshToken);

        // The previous refresh token is spent: commit the new one before anything else uses the access token.
        $this->entityManager->wrapInTransaction(static function () use ($token, $encryptedAccess, $encryptedRefresh, $tokens, $now): void {
            $token->rotate(
                $encryptedAccess,
                $encryptedRefresh,
                $now->addSeconds($tokens->expiresIn),
                $tokens->scopes,
                $now,
            );
        });

        return $tokens->accessToken;
    }

    private function fallbackRegion(TeslaApp $app): FleetRegion
    {
        foreach ($app->getEnabledRegions() as $region) {
            if ($region->isSupported()) {
                return $region;
            }
        }

        return FleetRegion::EuropeMiddleEastAfrica;
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::instance($this->clock->now())->utc();
    }
}
