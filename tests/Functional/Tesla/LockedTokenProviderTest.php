<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Tesla;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Shared\Service\SecretEncrypter;
use Polaris\Tesla\Domain\ConnectionStatus;
use Polaris\Tesla\Entity\OAuthToken;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Service\FleetApiError;
use Polaris\Tesla\Service\LockedTokenProvider;
use Polaris\Tesla\Service\OAuthClient;
use Polaris\Tesla\Service\TeslaNotConnected;
use Polaris\Tests\Support\SignsIn;
use Polaris\Tests\Support\TeslaFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(LockedTokenProvider::class)]
final class LockedTokenProviderTest extends KernelTestCase
{
    use SignsIn;
    use TeslaFixtures;

    private const string NOW = '2026-10-24 12:00:00 UTC';
    private const int MARGIN = 600;

    private EntityManagerInterface $entityManager;
    private SecretEncrypter $encrypter;
    private MockClock $clock;
    private InMemoryStore $store;

    /** @var list<array{string, string}> the method and URL of every HTTP call */
    private array $calls = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->encrypter = self::getContainer()->get(SecretEncrypter::class);
        $this->clock = new MockClock(self::NOW);
        $this->store = new InMemoryStore();
    }

    public function testAFreshTokenIsReturnedWithoutCallingTesla(): void
    {
        $app = $this->app();
        self::createToken($app, CarbonImmutable::parse(self::NOW)->addHours(2));

        $access = $this->provider([])->accessToken($app);

        self::assertSame('stored-access', $access);
        self::assertSame([], $this->calls);
    }

    public function testATokenInsideTheMarginIsRefreshedAndBothTokensAreRotated(): void
    {
        $app = $this->app();
        self::createToken($app, CarbonImmutable::parse(self::NOW)->addSeconds(self::MARGIN - 1));

        $access = $this->provider([$this->tokens('new-access', 'new-refresh', 28800)])->accessToken($app);

        self::assertSame('new-access', $access);
        self::assertCount(1, $this->calls);
        $this->entityManager->clear();
        $token = $this->storedToken();
        self::assertSame('new-access', $this->encrypter->decrypt($token->getEncryptedAccessToken()));
        self::assertSame('new-refresh', $this->encrypter->decrypt($token->getEncryptedRefreshToken()));
        self::assertEquals(CarbonImmutable::parse(self::NOW)->addSeconds(28800), $token->getExpiresAt());
        self::assertEquals(CarbonImmutable::parse(self::NOW), $token->getRefreshedAt());
        self::assertTrue($token->isConnected());
    }

    public function testForceRefreshesATokenThatIsNotDue(): void
    {
        $app = $this->app();
        self::createToken($app, CarbonImmutable::parse(self::NOW)->addHours(2));

        $access = $this->provider([$this->tokens('new-access', 'new-refresh', 28800)])->accessToken($app, true);

        self::assertSame('new-access', $access);
        self::assertCount(1, $this->calls);
        $this->entityManager->clear();
        self::assertSame('new-refresh', $this->encrypter->decrypt($this->storedToken()->getEncryptedRefreshToken()));
    }

    public function testTheRefreshSendsTheStoredRefreshTokenAndNoSecret(): void
    {
        $app = $this->app();
        self::createToken($app, CarbonImmutable::parse(self::NOW)->subMinute());
        $body = null;
        $provider = $this->providerWith(new MockHttpClient(function (string $method, string $url, array $options) use (&$body): MockResponse {
            $body = $options['body'];

            return $this->tokens('new-access', 'new-refresh', 28800);
        }));

        $provider->accessToken($app);

        self::assertIsString($body);
        parse_str($body, $fields);
        self::assertSame(['grant_type' => 'refresh_token', 'client_id' => 'fake-client-id', 'refresh_token' => 'stored-refresh'], $fields);
    }

    public function testTwoRefreshesOfTheSameAppCallTeslaOnce(): void
    {
        $app = $this->app();
        self::createToken($app, CarbonImmutable::parse(self::NOW)->subMinute());
        $provider = $this->provider([$this->tokens('new-access', 'new-refresh', 28800)]);

        $first = $provider->accessToken($app);
        $second = $provider->accessToken($app);

        self::assertSame('new-access', $first);
        self::assertSame('new-access', $second);
        self::assertCount(1, $this->calls);
        $this->entityManager->clear();
        self::assertCount(1, $this->entityManager->getRepository(OAuthToken::class)->findAll());
    }

    /**
     * The race the lock is for: this process read the token before another one refreshed it, then waited for
     * the lock. Once it holds the lock it must read the row again and reuse the other process's tokens.
     */
    public function testAProcessThatWaitedForTheLockReusesTheTokensAnotherOneStored(): void
    {
        $app = $this->app();
        $token = self::createToken($app, CarbonImmutable::parse(self::NOW)->subMinute());
        self::assertTrue($token->needsRefresh(CarbonImmutable::instance($this->clock->now()), self::MARGIN));

        // The other process refreshed and committed: the managed entity of this one is now stale.
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE oauth_token SET encrypted_access_token = :access, encrypted_refresh_token = :refresh, expires_at = :expires WHERE id = :id',
            [
                'access' => $this->encrypter->encrypt('other-access'),
                'refresh' => $this->encrypter->encrypt('other-refresh'),
                'expires' => CarbonImmutable::parse(self::NOW)->addHours(8)->format('Y-m-d H:i:s'),
                'id' => $token->getId(),
            ],
            ['id' => 'ulid'],
        );

        $access = $this->provider([])->accessToken($app);

        self::assertSame('other-access', $access);
        self::assertSame([], $this->calls, 'Tesla must not be called: the other process already spent the refresh token.');
    }

    public function testTheLockIsHeldWhileTeslaIsCalledAndReleasedAfterwards(): void
    {
        $app = $this->app();
        self::createToken($app, CarbonImmutable::parse(self::NOW)->subMinute());
        $heldDuringTheCall = null;
        $factory = new LockFactory($this->store);
        $provider = $this->providerWith(
            new MockHttpClient(function () use (&$heldDuringTheCall, $app): MockResponse {
                // Another process asking for the same lock, without waiting, must be refused.
                $heldDuringTheCall = !new LockFactory($this->store)
                    ->createLock('tesla_oauth_refresh_' . $app->getId()->toBase32())
                    ->acquire(false);

                return $this->tokens('new-access', 'new-refresh', 28800);
            }),
            $factory,
        );

        $provider->accessToken($app);

        self::assertTrue($heldDuringTheCall);
        $other = $factory->createLock('tesla_oauth_refresh_' . $app->getId()->toBase32());
        self::assertTrue($other->acquire(false), 'The lock must be released after the refresh.');
        $other->release();
    }

    public function testARefusedRefreshTokenMarksTheConnectionExpiredAndKeepsTheRow(): void
    {
        $app = $this->app();
        self::createToken($app, CarbonImmutable::parse(self::NOW)->subMinute());
        $provider = $this->provider([new MockResponse('{"error":"login_required"}', ['http_code' => 401])]);

        try {
            $provider->accessToken($app);
            self::fail('The connection should be reported as expired.');
        } catch (TeslaNotConnected) {
        }

        self::assertCount(1, $this->calls, 'No retry.');
        $this->entityManager->clear();
        $token = $this->storedToken();
        self::assertSame(ConnectionStatus::Expired, $token->getStatus());
        self::assertSame('stored-refresh', $this->encrypter->decrypt($token->getEncryptedRefreshToken()));

        // An expired connection is not tried again.
        try {
            $this->provider([])->accessToken($app);
            self::fail('The connection should still be expired.');
        } catch (TeslaNotConnected) {
        }
        self::assertSame(1, $this->callCount());
    }

    public function testAnOutageLeavesTheStoredTokensAlone(): void
    {
        $app = $this->app();
        self::createToken($app, CarbonImmutable::parse(self::NOW)->subMinute());
        $provider = $this->provider([new MockResponse('', ['http_code' => 503])]);

        try {
            $provider->accessToken($app);
            self::fail('The outage should be reported.');
        } catch (FleetApiError $error) {
            self::assertFalse($error->refreshTokenRejected());
        }

        self::assertCount(1, $this->calls);
        $this->entityManager->clear();
        $token = $this->storedToken();
        self::assertTrue($token->isConnected());
        self::assertSame('stored-refresh', $this->encrypter->decrypt($token->getEncryptedRefreshToken()));
    }

    public function testWithoutAConnectionThereIsNoToken(): void
    {
        $this->expectException(TeslaNotConnected::class);

        $this->provider([])->accessToken($this->app());
    }

    /**
     * PHPStan narrows the type of $calls after an earlier assertion, hence a method.
     */
    private function callCount(): int
    {
        return \count($this->calls);
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

    private function tokens(string $access, string $refresh, int $expiresIn): MockResponse
    {
        return new MockResponse((string) json_encode([
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => $expiresIn,
            'token_type' => 'Bearer',
        ]));
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function provider(array $responses): LockedTokenProvider
    {
        $queue = $responses;

        return $this->providerWith(new MockHttpClient(static function () use (&$queue): MockResponse {
            return array_shift($queue) ?? new MockResponse('unexpected call', ['http_code' => 500]);
        }));
    }

    private function providerWith(MockHttpClient $inner, ?LockFactory $factory = null): LockedTokenProvider
    {
        $calls = &$this->calls;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($inner, &$calls): \Symfony\Contracts\HttpClient\ResponseInterface {
            $calls[] = [$method, $url];

            return $inner->request($method, $url, $options);
        });

        return new LockedTokenProvider(
            $this->entityManager,
            new OAuthClient($client),
            $this->encrypter,
            $factory ?? new LockFactory($this->store),
            $this->clock,
            self::MARGIN,
        );
    }

    protected function tearDown(): void
    {
        unset($this->entityManager);
        parent::tearDown();
    }
}
