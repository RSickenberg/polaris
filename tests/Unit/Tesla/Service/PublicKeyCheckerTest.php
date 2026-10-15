<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Tesla\Service;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Tesla\Domain\AppDomain;
use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Domain\PemComparer;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Service\PublicKeyChecker;
use Polaris\Tesla\Service\PublicKeyCheckResult;
use Polaris\Tesla\Service\PublicKeyCheckStatus;
use Polaris\Tests\Support\Owner;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(PublicKeyChecker::class)]
#[CoversClass(PublicKeyCheckResult::class)]
final class PublicKeyCheckerTest extends TestCase
{
    private const string KEY = "-----BEGIN PUBLIC KEY-----\nMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE\n-----END PUBLIC KEY-----\n";
    private const string URL = 'https://polaris.example.com/.well-known/appspecific/com.tesla.3p.public-key.pem';

    public function testItFetchesTheWellKnownUrlWithoutFollowingRedirects(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options['max_redirects']];

            return new MockResponse(self::KEY);
        });

        $result = $this->checker($client)->check($this->app());

        self::assertSame(PublicKeyCheckStatus::Match, $result->status);
        self::assertTrue($result->isMatch());
        self::assertSame([['GET', self::URL, 0]], $requests);
    }

    public function testALineEndingDifferenceStillMatches(): void
    {
        $client = new MockHttpClient(new MockResponse(str_replace("\n", "\r\n", self::KEY)));

        self::assertTrue($this->checker($client)->check($this->app())->isMatch());
    }

    public function testAnotherKeyIsAMismatch(): void
    {
        $client = new MockHttpClient(new MockResponse(str_replace('MFkwEwYH', 'MFkwEwYX', self::KEY)));

        $result = $this->checker($client)->check($this->app());

        self::assertSame(PublicKeyCheckStatus::Mismatch, $result->status);
        self::assertStringContainsString('different key', $result->message);
    }

    public function testAnOversizedBodyIsAMismatch(): void
    {
        $client = new MockHttpClient(new MockResponse(self::KEY . str_repeat('x', 9000)));

        self::assertSame(PublicKeyCheckStatus::Mismatch, $this->checker($client)->check($this->app())->status);
    }

    public function testAMissingKeyIsNotFound(): void
    {
        $client = new MockHttpClient(new MockResponse('nope', ['http_code' => 404]));

        $result = $this->checker($client)->check($this->app());

        self::assertSame(PublicKeyCheckStatus::NotFound, $result->status);
        self::assertStringContainsString('HTTP 404', $result->message);
    }

    public function testAServerErrorIsReportedWithItsStatus(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 503]));

        $result = $this->checker($client)->check($this->app());

        self::assertSame(PublicKeyCheckStatus::NotFound, $result->status);
        self::assertStringContainsString('HTTP 503', $result->message);
    }

    public function testARedirectIsReportedNotFollowed(): void
    {
        $client = new MockHttpClient(new MockResponse('', [
            'http_code' => 301,
            'response_headers' => ['Location: https://www.example.com/key.pem'],
        ]));

        $result = $this->checker($client)->check($this->app());

        self::assertSame(PublicKeyCheckStatus::Redirected, $result->status);
        self::assertStringContainsString('HTTP 301', $result->message);
    }

    public function testATransportFailureIsReported(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['error' => 'Could not resolve host']));

        $result = $this->checker($client)->check($this->app());

        self::assertSame(PublicKeyCheckStatus::Unreachable, $result->status);
        self::assertStringContainsString('Could not resolve host', $result->message);
    }

    public function testWithoutADomainNothingIsFetched(): void
    {
        $client = new MockHttpClient(static fn (): never => self::fail('No request expected.'));
        $app = new TeslaApp(Owner::user(), self::KEY, 'v1:private', CarbonImmutable::parse('2026-10-20 12:00:00 UTC'));

        $result = $this->checker($client)->check($app);

        self::assertSame(PublicKeyCheckStatus::NotConfigured, $result->status);
        self::assertSame(0, $client->getRequestsCount());
    }

    private function checker(MockHttpClient $client): PublicKeyChecker
    {
        return new PublicKeyChecker($client, new PemComparer());
    }

    private function app(): TeslaApp
    {
        $app = new TeslaApp(Owner::user(), self::KEY, 'v1:private', CarbonImmutable::parse('2026-10-20 12:00:00 UTC'));
        $app->configure('client-id', 'v1:secret', AppDomain::fromString('polaris.example.com'), [FleetRegion::EuropeMiddleEastAfrica]);

        return $app;
    }
}
