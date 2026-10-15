<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Extension;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Extension\ConfiguredPublicKeyHosting;
use Polaris\Extension\ExternalPublicKeyHosting;
use Polaris\Extension\PolarisPublicKeyHosting;
use Polaris\Extension\PublicKeyHostingMode;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ExternalPublicKeyHosting::class)]
#[CoversClass(PolarisPublicKeyHosting::class)]
#[CoversClass(ConfiguredPublicKeyHosting::class)]
final class PublicKeyHostingTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/polaris-key-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testExternalHostingWritesThePublicKeyAtTheWellKnownPath(): void
    {
        $hosting = new ExternalPublicKeyHosting(new Filesystem(), $this->directory . '/');

        $hosting->publish("-----BEGIN PUBLIC KEY-----\nabc\n-----END PUBLIC KEY-----\n");

        self::assertFalse($hosting->servesPublicKey());
        self::assertStringEqualsFile(
            $this->directory . '/.well-known/appspecific/com.tesla.3p.public-key.pem',
            "-----BEGIN PUBLIC KEY-----\nabc\n-----END PUBLIC KEY-----\n",
        );
    }

    public function testExternalHostingNeedsADirectory(): void
    {
        $this->expectException(\RuntimeException::class);
        new ExternalPublicKeyHosting(new Filesystem(), '')->publish('key');
    }

    public function testPolarisHostingServesTheKeyAndWritesNothing(): void
    {
        $hosting = new PolarisPublicKeyHosting();

        $hosting->publish('key');

        self::assertTrue($hosting->servesPublicKey());
    }

    public function testTheConfiguredHostingFollowsTheMode(): void
    {
        $polaris = new ConfiguredPublicKeyHosting(new Filesystem(), PublicKeyHostingMode::Polaris, '');
        $external = new ConfiguredPublicKeyHosting(new Filesystem(), PublicKeyHostingMode::External, $this->directory);

        self::assertTrue($polaris->servesPublicKey());
        self::assertFalse($external->servesPublicKey());

        $external->publish('key');
        self::assertFileExists($this->directory . '/' . ExternalPublicKeyHosting::RELATIVE_PATH);
    }
}
