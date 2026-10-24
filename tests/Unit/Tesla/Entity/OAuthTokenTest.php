<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Tesla\Entity;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Account\Entity\User;
use Polaris\Tesla\Domain\ConnectionMethod;
use Polaris\Tesla\Domain\ConnectionStatus;
use Polaris\Tesla\Entity\OAuthToken;
use Polaris\Tesla\Entity\TeslaApp;

#[CoversClass(OAuthToken::class)]
final class OAuthTokenTest extends TestCase
{
    private const string NOW = '2026-10-24 12:00:00 UTC';

    public function testItIsDueWhenTheMarginReachesTheExpiry(): void
    {
        $token = $this->token(CarbonImmutable::parse(self::NOW)->addHour());

        self::assertFalse($token->needsRefresh(CarbonImmutable::parse(self::NOW), 600));
        self::assertTrue($token->needsRefresh(CarbonImmutable::parse(self::NOW)->addSeconds(3000), 600));
        self::assertTrue($token->needsRefresh(CarbonImmutable::parse(self::NOW)->addHours(2), 600));
    }

    public function testRotatingReplacesBothTokensAndReconnects(): void
    {
        $token = $this->token(CarbonImmutable::parse(self::NOW));
        $token->markExpired();
        self::assertSame(ConnectionStatus::Expired, $token->getStatus());

        $later = CarbonImmutable::parse(self::NOW)->addHours(9);
        $token->rotate('v1:new-access', 'v1:new-refresh', $later->addHours(8), 'openid', $later);

        self::assertSame('v1:new-access', $token->getEncryptedAccessToken());
        self::assertSame('v1:new-refresh', $token->getEncryptedRefreshToken());
        self::assertTrue($token->isConnected());
        self::assertEquals($later->addHours(8), $token->getExpiresAt());
        self::assertEquals($later, $token->getRefreshedAt());
        self::assertEquals(CarbonImmutable::parse(self::NOW), $token->getConnectedAt());
    }

    public function testSerializationLeavesTheCiphertextsOut(): void
    {
        $serialized = serialize($this->token(CarbonImmutable::parse(self::NOW)));

        self::assertStringNotContainsString('v1:access', $serialized);
        self::assertStringNotContainsString('v1:refresh', $serialized);
    }

    private function token(CarbonImmutable $expiresAt): OAuthToken
    {
        $now = CarbonImmutable::parse(self::NOW);
        $app = new TeslaApp(new User('driver@example.test', 'not-a-real-hash', $now), 'public', 'v1:private', $now);

        return new OAuthToken($app, 'v1:access', 'v1:refresh', $expiresAt, 'openid', ConnectionMethod::OAuth, 'https://fleet-api.prd.eu.vn.cloud.tesla.com', $now);
    }
}
