<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Tesla;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Shared\Service\SecretEncrypter;
use Polaris\Tesla\Domain\CallbackParameters;
use Polaris\Tesla\Domain\ConnectionMethod;
use Polaris\Tesla\Entity\OAuthToken;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Service\Client\OAuthClient;
use Polaris\Tesla\Service\Exception\ConnectionRejected;
use Polaris\Tesla\Service\RedirectUriProvider;
use Polaris\Tesla\Service\TeslaConnectionService;
use Polaris\Tests\Support\SignsIn;
use Polaris\Tests\Support\TeslaFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(TeslaConnectionService::class)]
#[CoversClass(RedirectUriProvider::class)]
final class TeslaConnectionServiceTest extends KernelTestCase
{
    use SignsIn;
    use TeslaFixtures;

    private const string NOW = '2026-10-24 12:00:00 UTC';
    private const string REDIRECT = 'https://polaris.example.com/tesla/callback';
    private const string STATE = 'fake-state';
    private const string EU_REGION = '{"response":{"region":"eu","fleet_api_base_url":"https://fleet-api.prd.eu.vn.cloud.tesla.com"}}';
    private const string NA_REGION = '{"response":{"region":"na","fleet_api_base_url":"https://fleet-api.prd.na.vn.cloud.tesla.com"}}';

    private EntityManagerInterface $entityManager;
    private SecretEncrypter $encrypter;

    /** @var list<array{string, string, string}> method, URL and body of every HTTP call */
    private array $calls = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->encrypter = self::getContainer()->get(SecretEncrypter::class);
    }

    public function testTheRedirectUriFallsBackToTheDefaultUri(): void
    {
        self::assertSame('https://polaris.example.com/tesla/callback', new RedirectUriProvider('', 'https://polaris.example.com/')->get());
        self::assertSame('http://localhost:8000/tesla/callback', new RedirectUriProvider(' http://localhost:8000/tesla/callback ', 'https://other.example.com')->get());
        self::assertSame('https://localhost/tesla/callback', new RedirectUriProvider('', 'https://localhost:443')->get());
        self::assertSame('http://localhost/tesla/callback', new RedirectUriProvider('', 'http://localhost:80/')->get());
        self::assertSame('https://localhost:8443/tesla/callback', new RedirectUriProvider('', 'https://localhost:8443')->get());
    }

    public function testTheAuthorizationRequestUsesTheConfiguredRedirectUriAndARandomState(): void
    {
        $app = $this->app();

        $first = $this->service([])->startAuthorization($app);
        $second = $this->service([])->startAuthorization($app);

        parse_str((string) parse_url($first->url, \PHP_URL_QUERY), $query);
        self::assertSame(self::REDIRECT, $query['redirect_uri']);
        self::assertSame($first->state, $query['state']);
        self::assertSame('fake-client-id', $query['client_id']);
        self::assertSame('openid offline_access vehicle_device_data vehicle_cmds', $query['scope']);
        self::assertNotSame($first->state, $second->state);
        self::assertSame([], $this->calls);
    }

    public function testNothingWorksWithoutConsent(): void
    {
        $app = self::createConfiguredApp(self::createUser(), false);
        $service = $this->service([]);

        foreach ([
            static fn () => $service->startAuthorization($app),
            static fn () => $service->completeFromCallback($app, new CallbackParameters('code', self::STATE), self::STATE),
            static fn () => $service->completeFromPastedUrl($app, 'https://x.test/cb?code=code&state=' . self::STATE, self::STATE),
            static fn () => $service->connectWithRefreshToken($app, 'fake-refresh'),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('The attempt should be refused.');
            } catch (ConnectionRejected $rejected) {
                self::assertSame(ConnectionRejected::NO_CONSENT, $rejected->key);
            }
        }

        self::assertSame([], $this->calls);
        self::assertSame([], $this->entityManager->getRepository(OAuthToken::class)->findAll());
    }

    public function testGivingConsentStoresWhenItWasGivenInUtc(): void
    {
        $app = self::createConfiguredApp(self::createUser(), false);

        $this->service([])->giveConsent($app);

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(TeslaApp::class)->findOneBy([]);
        self::assertInstanceOf(TeslaApp::class, $stored);
        self::assertEquals(CarbonImmutable::parse(self::NOW), $stored->getConsentGivenAt());
        self::assertSame('UTC', $stored->getConsentGivenAt()?->getTimezone()->getName());
    }

    public function testACallbackWithTheRightStateExchangesTheCodeAndStoresEncryptedTokens(): void
    {
        $app = $this->app();

        $result = $this->service([$this->tokens('first-access', 'first-refresh'), new MockResponse(self::EU_REGION)])
            ->completeFromCallback($app, new CallbackParameters('fake-code', self::STATE), self::STATE);

        self::assertFalse($result->regionDiffers);
        self::assertCount(2, $this->calls);
        [$exchangeMethod, $exchangeUrl, $exchangeBody] = $this->calls[0];
        self::assertSame('POST', $exchangeMethod);
        self::assertSame('https://fleet-auth.prd.vn.cloud.tesla.com/oauth2/v3/token', $exchangeUrl);
        parse_str($exchangeBody, $fields);
        self::assertSame('authorization_code', $fields['grant_type']);
        self::assertSame('fake-client-secret', $fields['client_secret']);
        self::assertSame('fake-code', $fields['code']);
        self::assertSame(self::REDIRECT, $fields['redirect_uri']);
        self::assertSame('https://fleet-api.prd.eu.vn.cloud.tesla.com', $fields['audience']);
        self::assertSame('https://fleet-api.prd.eu.vn.cloud.tesla.com/api/1/users/region', $this->calls[1][1]);

        $this->entityManager->clear();
        $token = $this->storedToken();
        self::assertStringStartsWith('v1:', $token->getEncryptedAccessToken());
        self::assertStringStartsWith('v1:', $token->getEncryptedRefreshToken());
        self::assertSame('first-access', $this->encrypter->decrypt($token->getEncryptedAccessToken()));
        self::assertSame('first-refresh', $this->encrypter->decrypt($token->getEncryptedRefreshToken()));
        self::assertEquals(CarbonImmutable::parse(self::NOW)->addSeconds(28800), $token->getExpiresAt());
        self::assertSame(ConnectionMethod::OAuth, $token->getMethod());
        self::assertSame('https://fleet-api.prd.eu.vn.cloud.tesla.com', $token->getFleetApiBaseUrl());
        self::assertSame('openid offline_access vehicle_device_data vehicle_cmds', $token->getScopes());
    }

    public function testAWrongOrMissingStateIsRefusedBeforeTeslaIsCalled(): void
    {
        $app = $this->app();
        $service = $this->service([]);

        foreach ([[self::STATE, 'other-state'], [self::STATE, null], [null, self::STATE], [null, null]] as [$expected, $received]) {
            try {
                $service->completeFromCallback($app, new CallbackParameters('fake-code', $received), $expected);
                self::fail('The state should be refused.');
            } catch (ConnectionRejected $rejected) {
                self::assertSame(ConnectionRejected::BAD_STATE, $rejected->key);
            }
        }

        self::assertSame([], $this->calls);
    }

    public function testADeniedOrCodelessCallbackIsRefused(): void
    {
        $app = $this->app();
        $service = $this->service([]);

        try {
            $service->completeFromCallback($app, new CallbackParameters(null, self::STATE, 'access_denied'), self::STATE);
            self::fail('Denied.');
        } catch (ConnectionRejected $rejected) {
            self::assertSame(ConnectionRejected::DENIED, $rejected->key);
        }

        try {
            $service->completeFromCallback($app, new CallbackParameters(null, self::STATE), self::STATE);
            self::fail('No code.');
        } catch (ConnectionRejected $rejected) {
            self::assertSame(ConnectionRejected::NO_CODE, $rejected->key);
        }
    }

    public function testAPastedCallbackUrlGoesThroughTheSameChecksAndExchange(): void
    {
        $app = $this->app();
        $url = 'https://polaris.local/tesla/callback?code=fake-code&state=' . self::STATE;

        try {
            $this->service([])->completeFromPastedUrl($app, str_replace(self::STATE, 'wrong', $url), self::STATE);
            self::fail('The state should be refused.');
        } catch (ConnectionRejected $rejected) {
            self::assertSame(ConnectionRejected::BAD_STATE, $rejected->key);
        }

        $result = $this->service([$this->tokens('pasted-access', 'pasted-refresh'), new MockResponse(self::EU_REGION)])
            ->completeFromPastedUrl($app, $url, self::STATE);

        self::assertSame(ConnectionMethod::PastedCallback, $result->token->getMethod());
        parse_str($this->calls[0][2], $fields);
        self::assertSame('fake-code', $fields['code']);
        self::assertSame(self::REDIRECT, $fields['redirect_uri']);
    }

    public function testAnInvalidPastedUrlIsRefused(): void
    {
        $this->expectException(ConnectionRejected::class);
        $this->expectExceptionMessage(ConnectionRejected::BAD_URL);

        $this->service([])->completeFromPastedUrl($this->app(), 'not a url', self::STATE);
    }

    public function testAnExpiredCodeIsReportedAsRefusedByTesla(): void
    {
        $app = $this->app();

        try {
            $this->service([new MockResponse('{"error":"invalid_auth_code"}', ['http_code' => 400])])
                ->completeFromCallback($app, new CallbackParameters('stale-code', self::STATE), self::STATE);
            self::fail('The exchange should be refused.');
        } catch (ConnectionRejected $rejected) {
            self::assertSame(ConnectionRejected::TOKEN_REFUSED, $rejected->key);
            self::assertStringNotContainsString('stale-code', (string) $rejected->getPrevious()?->getMessage());
        }

        self::assertSame([], $this->entityManager->getRepository(OAuthToken::class)->findAll());
    }

    public function testAnOutageDuringTheExchangeIsNotBlamedOnTheCode(): void
    {
        try {
            $this->service([new MockResponse('', ['http_code' => 503])])
                ->completeFromCallback($this->app(), new CallbackParameters('fake-code', self::STATE), self::STATE);
            self::fail('The exchange should fail.');
        } catch (ConnectionRejected $rejected) {
            self::assertSame(ConnectionRejected::TESLA_FAILED, $rejected->key);
        }
    }

    public function testAFailedRegionLookupStoresNothing(): void
    {
        try {
            $this->service([$this->tokens('a', 'r'), new MockResponse('{}', ['http_code' => 500])])
                ->completeFromCallback($this->app(), new CallbackParameters('fake-code', self::STATE), self::STATE);
            self::fail('The lookup should fail.');
        } catch (ConnectionRejected $rejected) {
            self::assertSame(ConnectionRejected::TESLA_FAILED, $rejected->key);
        }

        self::assertSame([], $this->entityManager->getRepository(OAuthToken::class)->findAll());
    }

    public function testAnAccountInAnotherRegionIsStoredWithItsOwnBaseUrlAndFlagged(): void
    {
        $result = $this->service([$this->tokens('a', 'r'), new MockResponse(self::NA_REGION)])
            ->completeFromCallback($this->app(), new CallbackParameters('fake-code', self::STATE), self::STATE);

        self::assertTrue($result->regionDiffers);
        self::assertSame('https://fleet-api.prd.na.vn.cloud.tesla.com', $result->token->getFleetApiBaseUrl());
    }

    public function testAPastedRefreshTokenIsRefreshedAtOnceAndOnlyTheResultIsStored(): void
    {
        $app = $this->app();

        $result = $this->service([$this->tokens('rotated-access', 'rotated-refresh'), new MockResponse(self::EU_REGION)])
            ->connectWithRefreshToken($app, '  pasted-refresh  ');

        self::assertSame(ConnectionMethod::PastedRefreshToken, $result->token->getMethod());
        parse_str($this->calls[0][2], $fields);
        self::assertSame(['grant_type' => 'refresh_token', 'client_id' => 'fake-client-id', 'refresh_token' => 'pasted-refresh'], $fields);
        $this->entityManager->clear();
        $token = $this->storedToken();
        self::assertSame('rotated-refresh', $this->encrypter->decrypt($token->getEncryptedRefreshToken()));
        self::assertStringNotContainsString('pasted-refresh', $token->getEncryptedRefreshToken());
    }

    public function testARefusedPastedRefreshTokenStoresNothingAndSaysSo(): void
    {
        $app = $this->app();

        try {
            $this->service([new MockResponse('{"error":"login_required"}', ['http_code' => 401])])
                ->connectWithRefreshToken($app, 'pasted-refresh');
            self::fail('The token should be refused.');
        } catch (ConnectionRejected $rejected) {
            self::assertSame(ConnectionRejected::TOKEN_REFUSED, $rejected->key);
        }

        self::assertCount(1, $this->calls, 'No retry.');
        self::assertSame([], $this->entityManager->getRepository(OAuthToken::class)->findAll());
    }

    public function testConnectingAgainReplacesAnExpiredConnection(): void
    {
        $app = $this->app();
        $old = self::createToken($app, CarbonImmutable::parse(self::NOW)->subDay());
        $old->markExpired();
        $this->entityManager->flush();

        $this->service([$this->tokens('new-access', 'new-refresh'), new MockResponse(self::EU_REGION)])
            ->connectWithRefreshToken($app, 'pasted-refresh');

        $this->entityManager->clear();
        $tokens = $this->entityManager->getRepository(OAuthToken::class)->findAll();
        self::assertCount(1, $tokens);
        self::assertTrue($tokens[0]->isConnected());
        self::assertSame('new-access', $this->encrypter->decrypt($tokens[0]->getEncryptedAccessToken()));
    }

    public function testDisconnectingDeletesTheTokensAndWithdrawsTheConsent(): void
    {
        $app = $this->app();
        self::createToken($app, CarbonImmutable::parse(self::NOW)->addHour());

        $this->service([])->disconnect($app);

        $this->entityManager->clear();
        self::assertSame([], $this->entityManager->getRepository(OAuthToken::class)->findAll());
        $stored = $this->entityManager->getRepository(TeslaApp::class)->findOneBy([]);
        self::assertInstanceOf(TeslaApp::class, $stored);
        self::assertFalse($stored->hasConsent());
    }

    public function testAnAppWithoutCredentialsCannotConnect(): void
    {
        $app = new TeslaApp(self::createUser(), 'public', 'v1:private', CarbonImmutable::parse(self::NOW));
        $app->giveConsent(CarbonImmutable::parse(self::NOW));
        $this->entityManager->persist($app);
        $this->entityManager->flush();

        $this->expectException(ConnectionRejected::class);
        $this->expectExceptionMessage(ConnectionRejected::NOT_CONFIGURED);

        $this->service([])->startAuthorization($app);
    }

    private function app(): TeslaApp
    {
        return self::createConfiguredApp(self::createUser());
    }

    private function storedToken(): OAuthToken
    {
        $token = $this->entityManager->getRepository(OAuthToken::class)->findOneBy([]);
        self::assertInstanceOf(OAuthToken::class, $token);

        return $token;
    }

    private function tokens(string $access, string $refresh): MockResponse
    {
        return new MockResponse((string) json_encode([
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => 28800,
            'token_type' => 'Bearer',
            'scope' => 'openid offline_access vehicle_device_data vehicle_cmds',
        ]));
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function service(array $responses): TeslaConnectionService
    {
        $queue = $responses;
        $calls = &$this->calls;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$queue, &$calls): ResponseInterface {
            $calls[] = [$method, $url, \is_string($options['body'] ?? null) ? $options['body'] : ''];

            return array_shift($queue) ?? new MockResponse('unexpected call', ['http_code' => 500]);
        });

        return new TeslaConnectionService(
            $this->entityManager,
            new OAuthClient($client),
            $this->encrypter,
            new RedirectUriProvider(self::REDIRECT, 'https://unused.example.com'),
            new MockClock(self::NOW),
        );
    }
}
