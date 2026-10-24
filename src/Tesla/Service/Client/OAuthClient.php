<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Client;

use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Domain\TeslaScopes;
use Polaris\Tesla\Domain\TokenSet;
use Polaris\Tesla\Domain\UserRegion;
use Polaris\Tesla\Service\Enum\FleetApiOperation;
use Polaris\Tesla\Service\Exception\FleetApiError;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The third-party token calls of Tesla (https://developer.tesla.com/docs/fleet-api/authentication/third-party-tokens):
 * the authorization URL, the code exchange, the refresh, and GET /api/1/users/region.
 *
 * Documented: the exchange needs `audience` (a Fleet API base URL) and the client secret; the refresh
 * needs only `client_id` and `refresh_token`. PKCE is not documented, so it is not used. A refresh
 * token is single use: no call here is ever retried.
 *
 * Not documented: the shape of the users/region answer. The client reads `response.region` and
 * `response.fleet_api_base_url` and refuses anything else with a clear error.
 */
final readonly class OAuthClient
{
    use SendsFleetApiRequests;

    private const string AUTHORIZE_URL = 'https://auth.tesla.com/oauth2/v3/authorize';

    public function __construct(private HttpClientInterface $client)
    {
    }

    public function authorizationUrl(string $clientId, string $redirectUri, string $state): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query(
            [
                'response_type' => 'code',
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'scope' => TeslaScopes::REQUESTED,
                'state' => $state,
            ],
            '',
            '&',
            \PHP_QUERY_RFC3986,
        );
    }

    /**
     * @throws FleetApiError
     */
    public function exchangeCode(
        FleetRegion $audience,
        string $clientId,
        #[\SensitiveParameter]
        string $clientSecret,
        #[\SensitiveParameter]
        string $code,
        string $redirectUri,
    ): TokenSet {
        $data = $this->send(
            FleetApiOperation::CodeExchange,
            $audience,
            'POST',
            $audience->tokenUrl(),
            [
                'body' => [
                    'grant_type' => 'authorization_code',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'code' => $code,
                    'audience' => $audience->baseUrl(),
                    'redirect_uri' => $redirectUri,
                ],
            ],
            [$clientSecret, $code],
        );

        return $this->tokenSet(FleetApiOperation::CodeExchange, $audience, $data);
    }

    /**
     * @throws FleetApiError {@see FleetApiError::refreshTokenRejected()} tells a refused token from an outage
     */
    public function refresh(
        FleetRegion $region,
        string $clientId,
        #[\SensitiveParameter]
        string $refreshToken,
    ): TokenSet {
        $data = $this->send(
            FleetApiOperation::Refresh,
            $region,
            'POST',
            $region->tokenUrl(),
            [
                'body' => [
                    'grant_type' => 'refresh_token',
                    'client_id' => $clientId,
                    'refresh_token' => $refreshToken,
                ],
            ],
            [$refreshToken],
        );

        return $this->tokenSet(FleetApiOperation::Refresh, $region, $data);
    }

    /**
     * @throws FleetApiError
     */
    public function userRegion(
        FleetRegion $region,
        #[\SensitiveParameter]
        string $accessToken,
    ): UserRegion {
        $data = $this->send(
            FleetApiOperation::UserRegion,
            $region,
            'GET',
            $region->baseUrl() . '/api/1/users/region',
            ['auth_bearer' => $accessToken],
            [$accessToken],
        );

        $response = $data['response'] ?? null;
        $baseUrl = \is_array($response) ? ($response['fleet_api_base_url'] ?? null) : null;
        $code = \is_array($response) ? ($response['region'] ?? null) : null;

        $userRegion = \is_string($baseUrl) ? FleetRegion::fromBaseUrl($baseUrl) : null;
        $userRegion ??= \is_string($code) ? FleetRegion::tryFrom(strtolower($code)) : null;

        if (null === $userRegion || !\is_string($baseUrl) || '' === $baseUrl) {
            throw FleetApiError::unexpected(FleetApiOperation::UserRegion, $region, 'the answer has no known region and Fleet API base URL');
        }

        return new UserRegion($userRegion, rtrim($baseUrl, '/'));
    }

    /**
     * @param array<mixed> $data
     */
    private function tokenSet(FleetApiOperation $operation, FleetRegion $region, array $data): TokenSet
    {
        $access = $data['access_token'] ?? null;
        $refresh = $data['refresh_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;
        $scope = $data['scope'] ?? null;

        if (!\is_string($access) || '' === $access) {
            throw FleetApiError::unexpected($operation, $region, 'the answer has no access token');
        }

        if (!\is_string($refresh) || '' === $refresh) {
            throw FleetApiError::unexpected($operation, $region, 'the answer has no refresh token');
        }

        if (!\is_int($expiresIn) || $expiresIn <= 0) {
            throw FleetApiError::unexpected($operation, $region, 'the answer has no valid expiry');
        }

        return new TokenSet(
            $access,
            $refresh,
            $expiresIn,
            \is_string($scope) && '' !== $scope ? $scope : TeslaScopes::REQUESTED,
        );
    }
}
