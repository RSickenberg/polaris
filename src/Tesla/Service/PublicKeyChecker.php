<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service;

use Polaris\Tesla\Domain\PemComparer;
use Polaris\Tesla\Entity\TeslaApp;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches https://<domain>/.well-known/appspecific/com.tesla.3p.public-key.pem, the URL Tesla
 * reads, and compares it with the stored public key. Redirects are not followed: whether Tesla
 * follows them is not documented, so the check stays strict.
 */
final readonly class PublicKeyChecker
{
    private const int TIMEOUT_SECONDS = 10;
    private const int MAX_KEY_BYTES = 8192;

    public function __construct(
        private HttpClientInterface $client,
        private PemComparer $comparer,
    ) {
    }

    public function check(TeslaApp $app): PublicKeyCheckResult
    {
        $domain = $app->getDomain();

        if (null === $domain) {
            return PublicKeyCheckResult::notConfigured();
        }

        $url = $domain->publicKeyUrl();

        try {
            $response = $this->client->request('GET', $url, [
                'max_redirects' => 0,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $status = $response->getStatusCode();

            if ($status >= 300 && $status < 400) {
                return PublicKeyCheckResult::redirected($url, $status);
            }

            if (200 !== $status) {
                return PublicKeyCheckResult::notFound($url, $status);
            }

            $served = $response->getContent(false);
        } catch (ExceptionInterface $failure) {
            return PublicKeyCheckResult::unreachable($url, $failure->getMessage());
        }

        return \strlen($served) <= self::MAX_KEY_BYTES && $this->comparer->same($served, $app->getPublicKey())
            ? PublicKeyCheckResult::matches($url)
            : PublicKeyCheckResult::mismatch($url);
    }
}
