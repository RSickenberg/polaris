<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service;

use Polaris\Tesla\Domain\AppDomain;
use Polaris\Tesla\Domain\FleetRegion;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The partner calls of the Fleet API that registering an app needs: a partner token
 * (client_credentials), POST /api/1/partner_accounts and GET /api/1/partner_accounts/public_key.
 *
 * The partner token is asked with the minimal scopes Polaris needs (PARTNER_SCOPE): a first real run
 * showed that the public key read-back answers 403 "missing scopes" to a token without any. The shape
 * of the register and public key responses is not documented, so the client trusts the status code
 * and reads only access_token and response.public_key.
 */
final readonly class FleetApiClient
{
    private const int TIMEOUT_SECONDS = 15;
    private const string PARTNER_SCOPE = 'openid vehicle_device_data';

    public function __construct(private HttpClientInterface $client)
    {
    }

    /**
     * @throws FleetApiError
     */
    public function partnerToken(
        FleetRegion $region,
        string $clientId,
        #[\SensitiveParameter]
        string $clientSecret,
    ): string {
        $data = $this->send(
            FleetApiOperation::Token,
            $region,
            'POST',
            $region->tokenUrl(),
            [
                'body' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => self::PARTNER_SCOPE,
                    'audience' => $region->baseUrl(),
                ],
            ],
            [$clientSecret],
        );

        $token = $data['access_token'] ?? null;

        if (!\is_string($token) || '' === $token) {
            throw FleetApiError::unexpected(FleetApiOperation::Token, $region, 'the answer has no access token');
        }

        return $token;
    }

    /**
     * @throws FleetApiError
     */
    public function registerPartner(
        FleetRegion $region,
        #[\SensitiveParameter]
        string $partnerToken,
        AppDomain $domain,
    ): void {
        $this->send(
            FleetApiOperation::Register,
            $region,
            'POST',
            $region->baseUrl() . '/api/1/partner_accounts',
            [
                'auth_bearer' => $partnerToken,
                'json' => ['domain' => $domain->toString()],
            ],
            [$partnerToken],
        );
    }

    /**
     * @return string the public key Tesla holds for the domain, as returned
     *
     * @throws FleetApiError
     */
    public function registeredPublicKey(
        FleetRegion $region,
        #[\SensitiveParameter]
        string $partnerToken,
        AppDomain $domain,
    ): string {
        $data = $this->send(
            FleetApiOperation::VerifyKey,
            $region,
            'GET',
            $region->baseUrl() . '/api/1/partner_accounts/public_key',
            [
                'auth_bearer' => $partnerToken,
                'query' => ['domain' => $domain->toString()],
            ],
            [$partnerToken],
        );

        $response = $data['response'] ?? null;
        $key = \is_array($response) ? ($response['public_key'] ?? null) : null;

        if (!\is_string($key) || '' === $key) {
            throw FleetApiError::unexpected(FleetApiOperation::VerifyKey, $region, 'the answer has no public key');
        }

        return $key;
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string>         $secrets
     *
     * @return array<mixed>
     *
     * @throws FleetApiError
     */
    private function send(
        FleetApiOperation $operation,
        FleetRegion $region,
        string $method,
        string $url,
        array $options,
        array $secrets,
    ): array {
        try {
            $response = $this->client->request($method, $url, $options + [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
            ]);

            return $this->decode($operation, $region, $response, $secrets);
        } catch (ExceptionInterface $failure) {
            throw FleetApiError::unreachable($operation, $region, $this->redact($failure->getMessage(), $secrets), $failure);
        }
    }

    /**
     * @param list<string> $secrets
     *
     * @return array<mixed>
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    private function decode(FleetApiOperation $operation, FleetRegion $region, ResponseInterface $response, array $secrets): array
    {
        $status = $response->getStatusCode();
        $body = $response->getContent(false);

        if ($status < 200 || $status >= 300) {
            throw FleetApiError::fromResponse($operation, $region, $status, $body, $secrets);
        }

        $data = json_decode($body, true);

        return \is_array($data) ? $data : [];
    }

    /**
     * @param list<string> $secrets
     */
    private function redact(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ('' !== $secret) {
                $text = str_replace($secret, '***', $text);
            }
        }

        return $text;
    }
}
