<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Tesla\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Tesla\Domain\KeyPair;
use Polaris\Tesla\Domain\KeyPairGenerator;

#[CoversClass(KeyPairGenerator::class)]
#[CoversClass(KeyPair::class)]
final class KeyPairGeneratorTest extends TestCase
{
    public function testGeneratesAPrime256v1PairWhoseKeysMatch(): void
    {
        $pair = new KeyPairGenerator()->generate();

        self::assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $pair->publicKeyPem);
        self::assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $pair->privateKeyPem());

        $private = openssl_pkey_get_private($pair->privateKeyPem());
        self::assertNotFalse($private);
        $details = openssl_pkey_get_details($private);
        self::assertIsArray($details);
        self::assertSame(\OPENSSL_KEYTYPE_EC, $details['type']);
        self::assertIsArray($details['ec']);
        self::assertSame('prime256v1', $details['ec']['curve_name']);
        self::assertSame($pair->publicKeyPem, $details['key']);
    }

    public function testEachPairIsNew(): void
    {
        $generator = new KeyPairGenerator();

        self::assertNotSame($generator->generate()->publicKeyPem, $generator->generate()->publicKeyPem);
    }

    public function testThePrivateKeyStaysOutOfSerializationAndDumps(): void
    {
        $pair = new KeyPairGenerator()->generate();

        self::assertStringNotContainsString('PRIVATE KEY', serialize($pair));
        self::assertStringNotContainsString('PRIVATE KEY', print_r($pair, true));
    }
}
