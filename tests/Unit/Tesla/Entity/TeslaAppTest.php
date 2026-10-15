<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Tesla\Entity;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Tesla\Domain\AppDomain;
use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tests\Support\Owner;

#[CoversClass(TeslaApp::class)]
final class TeslaAppTest extends TestCase
{
    public function testSerializationNeverIncludesThePrivateKey(): void
    {
        $app = new TeslaApp(Owner::user(), 'the public key', 'v1:ciphertext', CarbonImmutable::parse('2026-10-20 12:00:00 UTC'));

        $serialized = serialize($app);

        self::assertStringContainsString('the public key', $serialized);
        self::assertStringNotContainsString('v1:ciphertext', $serialized);
    }

    public function testReplacingTheKeyPair(): void
    {
        $app = new TeslaApp(Owner::user(), 'old', 'v1:old', CarbonImmutable::parse('2026-10-20 12:00:00 UTC'));

        $app->replaceKeyPair('new', 'v1:new', CarbonImmutable::parse('2026-10-21 12:00:00 +02:00'));

        self::assertSame('new', $app->getPublicKey());
        self::assertSame('v1:new', $app->getEncryptedPrivateKey());
        self::assertSame('2026-10-21 10:00:00', $app->getKeyGeneratedAt()->format('Y-m-d H:i:s'));
    }

    public function testSerializationNeverIncludesTheClientSecret(): void
    {
        $app = $this->app();
        $app->configure('client-id', 'v1:secret-ciphertext', AppDomain::fromString('polaris.example.com'), [FleetRegion::EuropeMiddleEastAfrica]);

        $serialized = serialize($app);

        self::assertStringContainsString('client-id', $serialized);
        self::assertStringNotContainsString('v1:secret-ciphertext', $serialized);
    }

    public function testAFreshAppIsNotConfigured(): void
    {
        $app = $this->app();

        self::assertFalse($app->isConfigured());
        self::assertNull($app->getClientId());
        self::assertNull($app->getDomain());
        self::assertSame([], $app->getEnabledRegions());
    }

    public function testConfiguringKeepsEachRegionOnce(): void
    {
        $app = $this->app();

        $app->configure(
            'client-id',
            'v1:secret',
            AppDomain::fromString('polaris.example.com'),
            [FleetRegion::EuropeMiddleEastAfrica, FleetRegion::NorthAmericaAsiaPacific, FleetRegion::EuropeMiddleEastAfrica],
        );

        self::assertTrue($app->isConfigured());
        self::assertSame('polaris.example.com', $app->getDomain()?->toString());
        self::assertSame(
            [FleetRegion::EuropeMiddleEastAfrica, FleetRegion::NorthAmericaAsiaPacific],
            $app->getEnabledRegions(),
        );
    }

    private function app(): TeslaApp
    {
        return new TeslaApp(Owner::user(), 'the public key', 'v1:ciphertext', CarbonImmutable::parse('2026-10-20 12:00:00 UTC'));
    }
}
