<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Account\Entity\User;
use Polaris\Shared\Service\SecretEncrypter;
use Polaris\Tesla\Domain\CallbackParameters;
use Polaris\Tesla\Domain\CallbackUrlParser;
use Polaris\Tesla\Domain\ConnectionMethod;
use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Domain\InvalidCallbackUrl;
use Polaris\Tesla\Domain\OAuthState;
use Polaris\Tesla\Domain\TokenSet;
use Polaris\Tesla\Entity\OAuthToken;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Service\Client\OAuthClient;
use Polaris\Tesla\Service\Dto\AuthorizationRequest;
use Polaris\Tesla\Service\Dto\ConnectionResult;
use Polaris\Tesla\Service\Exception\ConnectionRejected;
use Polaris\Tesla\Service\Exception\FleetApiError;
use Psr\Clock\ClockInterface;
use Random\RandomException;

/**
 * Connects the Tesla account of a user: the opt-in consent (Fleet API Agreement, clause 9.3.7), then one
 * of three ways to obtain tokens (the authorization redirect, a pasted callback URL, a pasted refresh
 * token). Every way checks the consent first, stores the tokens encrypted, and reads the user's region
 * (GET /api/1/users/region) to keep the Fleet API base URL.
 *
 * The `state` of the authorization request lives in the session of the caller: it is handed out by
 * {@see self::startAuthorization()} and passed back to the completion methods.
 */
final readonly class TeslaConnectionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OAuthClient $client,
        private SecretEncrypter $encrypter,
        private RedirectUriProvider $redirectUri,
        private ClockInterface $clock,
    ) {
    }

    public function findApp(User $owner): ?TeslaApp
    {
        return $this->entityManager->getRepository(TeslaApp::class)->findOneBy(['owner' => $owner]);
    }

    public function findToken(TeslaApp $app): ?OAuthToken
    {
        return $this->entityManager->getRepository(OAuthToken::class)->findOneBy(['teslaApp' => $app]);
    }

    /**
     * The redirect URI that Tesla must have in the allowed redirect URIs of the app.
     */
    public function redirectUri(): string
    {
        return $this->redirectUri->get();
    }

    /**
     * @throws ORMException
     */
    public function giveConsent(TeslaApp $app): void
    {
        $app->giveConsent($this->now());
        $this->entityManager->flush();
    }

    /**
     * Forgets the tokens and the consent. Tesla still lists the app until the user revokes it in their
     * Tesla account, which the interface says.
     *
     * @throws ORMException
     */
    public function disconnect(TeslaApp $app): void
    {
        $token = $this->findToken($app);

        if ($token instanceof OAuthToken) {
            $this->entityManager->remove($token);
        }

        $app->withdrawConsent();
        $this->entityManager->flush();
    }

    /**
     * @throws ConnectionRejected
     * @throws RandomException
     */
    public function startAuthorization(TeslaApp $app, bool $promptMissingScopes = false): AuthorizationRequest
    {
        $this->requireReady($app);

        $state = OAuthState::generate();

        return new AuthorizationRequest(
            $this->client->authorizationUrl((string) $app->getClientId(), $this->redirectUri->get(), $state, $promptMissingScopes),
            $state,
        );
    }

    /**
     * @throws ConnectionRejected
     * @throws \SodiumException
     * @throws ORMException
     * @throws RandomException
     */
    public function completeFromCallback(TeslaApp $app, CallbackParameters $callback, ?string $expectedState): ConnectionResult
    {
        return $this->complete($app, $callback, $expectedState, ConnectionMethod::OAuth);
    }

    /**
     * The same checks and the same exchange as the redirect, for a user whose browser could not reach
     * the callback.
     *
     * @throws ConnectionRejected
     * @throws \SodiumException
     * @throws ORMException
     * @throws RandomException
     */
    public function completeFromPastedUrl(TeslaApp $app, #[\SensitiveParameter] string $url, ?string $expectedState): ConnectionResult
    {
        try {
            $callback = CallbackUrlParser::parse($url);
        } catch (InvalidCallbackUrl $invalid) {
            throw new ConnectionRejected(ConnectionRejected::BAD_URL, $invalid);
        }

        return $this->complete($app, $callback, $expectedState, ConnectionMethod::PastedCallback);
    }

    /**
     * Does one refresh right away, which spends the pasted token and proves it works, and stores only the
     * tokens Tesla returned.
     *
     * @throws ConnectionRejected
     * @throws ORMException
     * @throws RandomException
     * @throws \SodiumException
     */
    public function connectWithRefreshToken(TeslaApp $app, #[\SensitiveParameter] string $refreshToken): ConnectionResult
    {
        $this->requireReady($app, false);

        $refreshToken = trim($refreshToken);
        if ('' === $refreshToken) {
            throw new ConnectionRejected(ConnectionRejected::TOKEN_REFUSED);
        }

        $audience = $this->audience($app);

        try {
            $tokens = $this->client->refresh($audience, (string) $app->getClientId(), $refreshToken);
        } catch (FleetApiError $failure) {
            throw $this->rejection($failure, $failure->refreshTokenRejected());
        }

        return $this->store($app, $audience, $tokens, ConnectionMethod::PastedRefreshToken);
    }

    /**
     * @throws ConnectionRejected
     * @throws \SodiumException
     * @throws ORMException
     * @throws RandomException
     */
    private function complete(TeslaApp $app, CallbackParameters $callback, ?string $expectedState, ConnectionMethod $method): ConnectionResult
    {
        $this->requireReady($app);

        if (null !== $callback->error) {
            throw new ConnectionRejected(ConnectionRejected::DENIED);
        }

        if (!OAuthState::matches($expectedState, $callback->state)) {
            throw new ConnectionRejected(ConnectionRejected::BAD_STATE);
        }

        if (null === $callback->code) {
            throw new ConnectionRejected(ConnectionRejected::NO_CODE);
        }

        $audience = $this->audience($app);

        try {
            $tokens = $this->client->exchangeCode(
                $audience,
                (string) $app->getClientId(),
                $this->encrypter->decrypt((string) $app->getEncryptedClientSecret()),
                $callback->code,
                $this->redirectUri->get(),
            );
        } catch (FleetApiError $failure) {
            throw $this->rejection($failure, null !== $failure->httpStatus && $failure->httpStatus < 500);
        }

        return $this->store($app, $audience, $tokens, $method);
    }

    /**
     * @throws ORMException
     * @throws RandomException
     * @throws \SodiumException
     */
    private function store(TeslaApp $app, FleetRegion $audience, TokenSet $tokens, ConnectionMethod $method): ConnectionResult
    {
        try {
            $userRegion = $this->client->userRegion($audience, $tokens->accessToken);
        } catch (FleetApiError $failure) {
            throw $this->rejection($failure, false);
        }

        $now = $this->now();
        $token = new OAuthToken(
            $app,
            $this->encrypter->encrypt($tokens->accessToken),
            $this->encrypter->encrypt($tokens->refreshToken),
            $now->addSeconds($tokens->expiresIn),
            $tokens->scopes,
            $method,
            $userRegion->baseUrl,
            $now,
        );

        $this->entityManager->wrapInTransaction(function () use ($app, $token): void {
            $previous = $this->findToken($app);
            if ($previous instanceof OAuthToken) {
                $this->entityManager->remove($previous);
                $this->entityManager->flush();
            }

            $this->entityManager->persist($token);
        });

        return new ConnectionResult($token, $userRegion->region !== $audience);
    }

    /**
     * @throws ConnectionRejected
     */
    private function requireReady(TeslaApp $app, bool $needsSecret = true): void
    {
        if (null === $app->getClientId() || ($needsSecret && null === $app->getEncryptedClientSecret()) || [] === $this->supportedRegions($app)) {
            throw new ConnectionRejected(ConnectionRejected::NOT_CONFIGURED);
        }

        if (!$app->hasConsent()) {
            throw new ConnectionRejected(ConnectionRejected::NO_CONSENT);
        }
    }

    /**
     * The region the tokens are requested for: the first enabled one. A Tesla account lives in one region,
     * and most installs enable one.
     */
    private function audience(TeslaApp $app): FleetRegion
    {
        return $this->supportedRegions($app)[0] ?? throw new ConnectionRejected(ConnectionRejected::NOT_CONFIGURED);
    }

    /**
     * @return list<FleetRegion>
     */
    private function supportedRegions(TeslaApp $app): array
    {
        return array_values(array_filter(
            $app->getEnabledRegions(),
            static fn (FleetRegion $region): bool => $region->isSupported(),
        ));
    }

    private function rejection(FleetApiError $failure, bool $refusedByTesla): ConnectionRejected
    {
        return new ConnectionRejected(
            $refusedByTesla ? ConnectionRejected::TOKEN_REFUSED : ConnectionRejected::TESLA_FAILED,
            $failure,
        );
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::instance($this->clock->now())->utc();
    }
}
