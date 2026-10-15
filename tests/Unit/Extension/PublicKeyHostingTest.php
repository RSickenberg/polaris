<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Extension;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testExternalHostingNeedsADirectoryOrAUrl(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('POLARIS_KEY_HOSTING_URL');
        new ExternalPublicKeyHosting(new Filesystem(), '')->publish('key');
    }

    public function testExternalHostingWithOnlyAUrlWritesNothingAndExposesTheUrl(): void
    {
        $url = 'https://keys.example.org/.well-known/appspecific/com.tesla.3p.public-key.pem';
        $hosting = new ExternalPublicKeyHosting(new Filesystem(), '', $url);

        $hosting->publish('key');

        self::assertSame($url, $hosting->publicKeyUrl());
        self::assertFalse($hosting->servesPublicKey());
        self::assertDirectoryDoesNotExist($this->directory);
    }

    public function testADirectoryAndAUrlTogetherWriteTheFileAndKeepTheUrl(): void
    {
        $url = 'https://keys.example.org/key.pem';
        $hosting = new ExternalPublicKeyHosting(new Filesystem(), $this->directory, $url);

        $hosting->publish('key');

        self::assertSame($url, $hosting->publicKeyUrl());
        self::assertFileExists($this->directory . '/' . ExternalPublicKeyHosting::RELATIVE_PATH);
    }

    public function testWithoutAUrlTheAppDomainIsUsed(): void
    {
        self::assertNull(new ExternalPublicKeyHosting(new Filesystem(), $this->directory)->publicKeyUrl());
        self::assertNull(new PolarisPublicKeyHosting()->publicKeyUrl());
    }

    #[DataProvider('invalidUrls')]
    public function testTheUrlMustBeAnHttpsFileUrl(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('POLARIS_KEY_HOSTING_URL');

        new ExternalPublicKeyHosting(new Filesystem(), '', $url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUrls(): iterable
    {
        yield 'http' => ['http://keys.example.org/key.pem'];
        yield 'no scheme' => ['keys.example.org/key.pem'];
        yield 'no path' => ['https://keys.example.org'];
        yield 'query' => ['https://keys.example.org/key.pem?raw=1'];
        yield 'fragment' => ['https://keys.example.org/key.pem#top'];
        yield 'blob page' => ['not a url'];
    }

    public function testPolarisHostingServesTheKeyAndWritesNothing(): void
    {
        $hosting = new PolarisPublicKeyHosting();

        $hosting->publish('key');

        self::assertTrue($hosting->servesPublicKey());
    }

    public function testTheConfiguredHostingFollowsTheMode(): void
    {
        $polaris = new ConfiguredPublicKeyHosting(new Filesystem(), PublicKeyHostingMode::Polaris, '', '');
        $external = new ConfiguredPublicKeyHosting(new Filesystem(), PublicKeyHostingMode::External, $this->directory, 'https://keys.example.org/key.pem');

        self::assertTrue($polaris->servesPublicKey());
        self::assertFalse($external->servesPublicKey());
        self::assertNull($polaris->publicKeyUrl());
        self::assertSame('https://keys.example.org/key.pem', $external->publicKeyUrl());

        $external->publish('key');
        self::assertFileExists($this->directory . '/' . ExternalPublicKeyHosting::RELATIVE_PATH);
    }
}
