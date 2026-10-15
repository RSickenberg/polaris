<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Tesla\Service;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Extension\PolarisPublicKeyHosting;
use Polaris\Shared\Service\SecretEncrypter;
use Polaris\Tesla\Domain\AppDomain;
use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Domain\KeyPair;
use Polaris\Tesla\Domain\KeyPairGenerator;
use Polaris\Tesla\Domain\PemComparer;
use Polaris\Tesla\Domain\PublicKeyFormat;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Service\FleetApiClient;
use Polaris\Tesla\Service\PartnerRegistrationService;
use Polaris\Tesla\Service\PublicKeyChecker;
use Polaris\Tesla\Service\RegistrationReport;
use Polaris\Tesla\Service\RegistrationStatus;
use Polaris\Tests\Support\Owner;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(PartnerRegistrationService::class)]
#[CoversClass(RegistrationReport::class)]
final class PartnerRegistrationServiceTest extends TestCase
{
    private const string SECRET = 'plain-client-secret';
    private const string KEY_URL = 'https://polaris.example.com/.well-known/appspecific/com.tesla.3p.public-key.pem';
    private const string TOKEN_URL = 'https://fleet-auth.prd.vn.cloud.tesla.com/oauth2/v3/token';
    private const string NA = 'https://fleet-api.prd.na.vn.cloud.tesla.com';
    private const string EU = 'https://fleet-api.prd.eu.vn.cloud.tesla.com';

    private KeyPair $keyPair;

    private SecretEncrypter $encrypter;

    /** @var list<string> "METHOD url" of every request, in order */
    private array $requests = [];

    /** @var array<string, \Closure(): MockResponse> answers by "METHOD url" */
    private array $answers = [];

    protected function setUp(): void
    {
        $this->keyPair = new KeyPairGenerator()->generate();
        $this->encrypter = new SecretEncrypter(base64_encode(random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
        $this->requests = [];
        $this->answers = [
            'GET ' . self::KEY_URL => fn (): MockResponse => new MockResponse($this->keyPair->publicKeyPem),
            'POST ' . self::TOKEN_URL => static fn (): MockResponse => new MockResponse('{"access_token":"partner-token"}'),
        ];
        foreach ([self::NA, self::EU] as $base) {
            $this->answers['POST ' . $base . '/api/1/partner_accounts'] = static fn (): MockResponse => new MockResponse('{"response":{}}');
            $this->answers['GET ' . $base . '/api/1/partner_accounts/public_key?domain=polaris.example.com'] = fn (): MockResponse => new MockResponse(
                json_encode(['response' => ['public_key' => new PublicKeyFormat(new PemComparer())->uncompressedPointHex($this->keyPair->publicKeyPem)]], \JSON_THROW_ON_ERROR),
            );
        }
    }

    public function testItRegistersEveryEnabledRegionAfterTheKeyCheck(): void
    {
        $report = $this->service()->register($this->app([FleetRegion::NorthAmericaAsiaPacific, FleetRegion::EuropeMiddleEastAfrica]));

        self::assertTrue($report->isSuccess());
        self::assertNull($report->blocker);
        self::assertTrue($report->keyCheck?->isMatch());
        self::assertSame(
            [
                'GET ' . self::KEY_URL,
                'POST ' . self::TOKEN_URL,
                'POST ' . self::NA . '/api/1/partner_accounts',
                'GET ' . self::NA . '/api/1/partner_accounts/public_key?domain=polaris.example.com',
                'POST ' . self::TOKEN_URL,
                'POST ' . self::EU . '/api/1/partner_accounts',
                'GET ' . self::EU . '/api/1/partner_accounts/public_key?domain=polaris.example.com',
            ],
            $this->requests,
        );
        self::assertCount(2, $report->results);
        foreach ($report->results as $result) {
            self::assertSame(RegistrationStatus::Registered, $result->status);
        }
    }

    public function testNothingIsSentToTeslaWhenTheKeyCheckFails(): void
    {
        $this->answers['GET ' . self::KEY_URL] = static fn (): MockResponse => new MockResponse('', ['http_code' => 404]);

        $report = $this->service()->register($this->app([FleetRegion::EuropeMiddleEastAfrica]));

        self::assertFalse($report->isSuccess());
        self::assertStringContainsString('not registered', (string) $report->blocker);
        self::assertStringContainsString('HTTP 404', (string) $report->blocker);
        self::assertSame([], $report->results);
        self::assertSame(['GET ' . self::KEY_URL], $this->requests);
    }

    public function testAFailingRegionDoesNotStopTheOthers(): void
    {
        $this->answers['POST ' . self::EU . '/api/1/partner_accounts'] = static fn (): MockResponse => new MockResponse('{"error":"forbidden"}', ['http_code' => 403]);

        $report = $this->service()->register($this->app([FleetRegion::EuropeMiddleEastAfrica, FleetRegion::NorthAmericaAsiaPacific]));

        self::assertFalse($report->isSuccess());
        self::assertSame(RegistrationStatus::Failed, $report->results[0]->status);
        self::assertSame(FleetRegion::EuropeMiddleEastAfrica, $report->results[0]->region);
        self::assertStringContainsString('HTTP 403', $report->results[0]->message);
        self::assertSame(RegistrationStatus::Registered, $report->results[1]->status);
    }

    public function testAWrongTokenIsReportedWithoutLeakingTheSecret(): void
    {
        $this->answers['POST ' . self::TOKEN_URL] = static fn (): MockResponse => new MockResponse(
            '{"error":"invalid_client","error_description":"bad ' . self::SECRET . '"}',
            ['http_code' => 401],
        );

        $report = $this->service()->register($this->app([FleetRegion::EuropeMiddleEastAfrica]));

        self::assertSame(RegistrationStatus::Failed, $report->results[0]->status);
        self::assertStringContainsString('check the client ID and the client secret', $report->results[0]->message);
        self::assertStringNotContainsString(self::SECRET, $report->results[0]->message);
    }

    public function testAnotherKeyAtTeslaIsAFailure(): void
    {
        $other = new KeyPairGenerator()->generate();
        $this->answers['GET ' . self::EU . '/api/1/partner_accounts/public_key?domain=polaris.example.com'] = static fn (): MockResponse => new MockResponse(
            json_encode(['response' => ['public_key' => $other->publicKeyPem]], \JSON_THROW_ON_ERROR),
        );

        $report = $this->service()->register($this->app([FleetRegion::EuropeMiddleEastAfrica]));

        self::assertSame(RegistrationStatus::Failed, $report->results[0]->status);
        self::assertStringContainsString('not the stored one', $report->results[0]->message);
    }

    public function testChinaIsRefusedWithoutAnyTeslaCall(): void
    {
        $report = $this->service()->register($this->app([FleetRegion::China]));

        self::assertFalse($report->isSuccess());
        self::assertSame(RegistrationStatus::Refused, $report->results[0]->status);
        self::assertStringContainsString('not supported', $report->results[0]->message);
        self::assertSame(['GET ' . self::KEY_URL], $this->requests);
    }

    public function testAnUnconfiguredAppIsBlockedBeforeAnyRequest(): void
    {
        $app = new TeslaApp(Owner::user(), $this->keyPair->publicKeyPem, 'v1:private', CarbonImmutable::parse('2026-10-20 12:00:00 UTC'));

        $report = $this->service()->register($app);

        self::assertFalse($report->isSuccess());
        self::assertStringContainsString('polaris:tesla:configure', (string) $report->blocker);
        self::assertSame([], $this->requests);
    }

    public function testAnUnreadableSecretIsBlockedAfterTheKeyCheck(): void
    {
        $app = $this->app([FleetRegion::EuropeMiddleEastAfrica]);
        $app->configure('client-id', 'v1:not-a-valid-box', AppDomain::fromString('polaris.example.com'), [FleetRegion::EuropeMiddleEastAfrica]);

        $report = $this->service()->register($app);

        self::assertStringContainsString('cannot be decrypted', (string) $report->blocker);
        self::assertSame(['GET ' . self::KEY_URL], $this->requests);
    }

    /**
     * @param list<FleetRegion> $regions
     */
    private function app(array $regions): TeslaApp
    {
        $app = new TeslaApp(Owner::user(), $this->keyPair->publicKeyPem, 'v1:private', CarbonImmutable::parse('2026-10-20 12:00:00 UTC'));
        $app->configure('client-id', $this->encrypter->encrypt(self::SECRET), AppDomain::fromString('polaris.example.com'), $regions);

        return $app;
    }

    private function service(): PartnerRegistrationService
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            $key = $method . ' ' . $url;
            $this->requests[] = $key;

            return ($this->answers[$key] ?? self::fail('Unexpected request: ' . $key))();
        });

        return new PartnerRegistrationService(
            new PublicKeyChecker($client, new PemComparer(), new PolarisPublicKeyHosting()),
            new FleetApiClient($client),
            $this->encrypter,
            new PublicKeyFormat(new PemComparer()),
        );
    }
}
