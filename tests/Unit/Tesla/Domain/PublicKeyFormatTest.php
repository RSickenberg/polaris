<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Tesla\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Tesla\Domain\KeyPairGenerator;
use Polaris\Tesla\Domain\PemComparer;
use Polaris\Tesla\Domain\PublicKeyFormat;

#[CoversClass(PublicKeyFormat::class)]
final class PublicKeyFormatTest extends TestCase
{
    public function testTheUncompressedPointIsHexOfTheEcPoint(): void
    {
        $pair = new KeyPairGenerator()->generate();

        $hex = $this->format()->uncompressedPointHex($pair->publicKeyPem);

        self::assertNotNull($hex);
        self::assertSame(130, \strlen($hex));
        self::assertStringStartsWith('04', $hex);
        self::assertSame($hex, strtolower($hex));
    }

    public function testItAcceptsTheHexOfTheStoredKey(): void
    {
        $pair = new KeyPairGenerator()->generate();
        $hex = (string) $this->format()->uncompressedPointHex($pair->publicKeyPem);

        self::assertTrue($this->format()->sameKey($pair->publicKeyPem, $hex));
        self::assertTrue($this->format()->sameKey($pair->publicKeyPem, strtoupper($hex)));
    }

    public function testItAcceptsThePemOfTheStoredKey(): void
    {
        $pair = new KeyPairGenerator()->generate();

        self::assertTrue($this->format()->sameKey($pair->publicKeyPem, str_replace("\n", "\r\n", $pair->publicKeyPem)));
    }

    public function testAnotherKeyDoesNotMatch(): void
    {
        $stored = new KeyPairGenerator()->generate();
        $other = new KeyPairGenerator()->generate();

        self::assertFalse($this->format()->sameKey($stored->publicKeyPem, $other->publicKeyPem));
        self::assertFalse($this->format()->sameKey($stored->publicKeyPem, (string) $this->format()->uncompressedPointHex($other->publicKeyPem)));
        self::assertFalse($this->format()->sameKey($stored->publicKeyPem, 'not a key'));
        self::assertFalse($this->format()->sameKey($stored->publicKeyPem, ''));
    }

    public function testAnInvalidPemHasNoPoint(): void
    {
        self::assertNull($this->format()->uncompressedPointHex('not a pem'));
    }

    private function format(): PublicKeyFormat
    {
        return new PublicKeyFormat(new PemComparer());
    }
}
