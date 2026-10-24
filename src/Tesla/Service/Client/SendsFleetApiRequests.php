<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Client;

use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Service\Enum\FleetApiOperation;
use Polaris\Tesla\Service\Exception\FleetApiError;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends one Fleet API or Tesla auth request and decodes the JSON answer. The using class holds the
 * HTTP client. Every error carries what was attempted and has the given secrets redacted, so a token
 * or a client secret never reaches a message or a log.
 */
trait SendsFleetApiRequests
{
    private const int TIMEOUT_SECONDS = 15;

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
