<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Shared\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Shared\Service\DecryptionFailed;
use Polaris\Shared\Service\SecretEncrypter;

#[CoversClass(SecretEncrypter::class)]
final class SecretEncrypterTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $encrypter = new SecretEncrypter(self::key());

        $ciphertext = $encrypter->encrypt('a secret value');

        self::assertStringStartsWith('v1:', $ciphertext);
        self::assertStringNotContainsString('a secret value', $ciphertext);
        self::assertSame('a secret value', $encrypter->decrypt($ciphertext));
    }

    public function testTheSameValueGivesADifferentCiphertextEachTime(): void
    {
        $encrypter = new SecretEncrypter(self::key());

        self::assertNotSame($encrypter->encrypt('x'), $encrypter->encrypt('x'));
    }

    public function testAnotherKeyCannotDecrypt(): void
    {
        $ciphertext = new SecretEncrypter(self::key())->encrypt('a secret value');

        $this->expectException(DecryptionFailed::class);
        new SecretEncrypter(self::key())->decrypt($ciphertext);
    }

    public function testAnAlteredValueFails(): void
    {
        $encrypter = new SecretEncrypter(self::key());
        $ciphertext = $encrypter->encrypt('a secret value');
        $altered = substr($ciphertext, 0, -4) . 'AAAA';

        $this->expectException(DecryptionFailed::class);
        $encrypter->decrypt($altered);
    }

    public function testAnUnknownVersionOrGarbageFails(): void
    {
        $encrypter = new SecretEncrypter(self::key());

        foreach (['', 'plain', 'v2:' . base64_encode(random_bytes(40)), 'v1:%%%'] as $value) {
            try {
                $encrypter->decrypt($value);
                self::fail(\sprintf('"%s" should not decrypt.', $value));
            } catch (DecryptionFailed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRefusesAKeyOfTheWrongSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SecretEncrypter(base64_encode('too short'));
    }

    private static function key(): string
    {
        return base64_encode(random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }
}
