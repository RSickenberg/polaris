<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Tesla\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Tesla\Domain\InvalidKeyPair;
use Polaris\Tesla\Domain\KeyPairGenerator;
use Polaris\Tesla\Domain\KeyPairImporter;

#[CoversClass(KeyPairImporter::class)]
#[CoversClass(InvalidKeyPair::class)]
final class KeyPairImporterTest extends TestCase
{
    public function testItDerivesThePublicKeyOfAGeneratedPrivateKey(): void
    {
        $generated = new KeyPairGenerator()->generate();

        $imported = new KeyPairImporter()->fromPrivateKey($generated->privateKeyPem());

        self::assertSame($generated->publicKeyPem, $imported->publicKeyPem);
        self::assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $imported->privateKeyPem());
    }

    public function testItAcceptsASec1Key(): void
    {
        $generated = new KeyPairGenerator()->generate();
        $key = openssl_pkey_get_private($generated->privateKeyPem());
        self::assertNotFalse($key);
        $sec1 = $this->exportSec1($key);

        $imported = new KeyPairImporter()->fromPrivateKey($sec1);

        self::assertStringStartsWith('-----BEGIN EC PRIVATE KEY-----', $sec1);
        self::assertSame($generated->publicKeyPem, $imported->publicKeyPem);
        self::assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $imported->privateKeyPem());
    }

    public function testTheImportedPrivateKeyIsNeverInDumps(): void
    {
        $imported = new KeyPairImporter()->fromPrivateKey(new KeyPairGenerator()->generate()->privateKeyPem());

        self::assertStringNotContainsString('PRIVATE KEY', print_r($imported, true));
    }

    #[DataProvider('unusableKeys')]
    public function testItRejectsWhatIsNotAPrime256v1PrivateKey(string $pem, string $message): void
    {
        $this->expectException(InvalidKeyPair::class);
        $this->expectExceptionMessage($message);

        new KeyPairImporter()->fromPrivateKey($pem);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusableKeys(): iterable
    {
        yield 'empty' => ['', 'not a PEM private key'];
        yield 'text' => ['hello', 'not a PEM private key'];
        yield 'public key' => [new KeyPairGenerator()->generate()->publicKeyPem, 'not a PEM private key'];
        yield 'truncated' => ["-----BEGIN PRIVATE KEY-----\nabcd\n-----END PRIVATE KEY-----\n", 'cannot be read'];
        yield 'passphrase' => ["-----BEGIN ENCRYPTED PRIVATE KEY-----\nabcd\n-----END ENCRYPTED PRIVATE KEY-----\n", 'passphrase'];
        yield 'other curve' => [self::export(['curve_name' => 'secp384r1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]), 'prime256v1'];
        yield 'rsa' => [self::export(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]), 'elliptic curve'];
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function export(array $options): string
    {
        $key = openssl_pkey_new($options);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        self::assertIsString($pem);

        return $pem;
    }

    private function exportSec1(\OpenSSLAsymmetricKey $key): string
    {
        // PHP exports PKCS8. The SEC1 form ("EC PRIVATE KEY") is built by hand from the key parameters:
        // SEQUENCE { version 1, private key, [0] curve prime256v1, [1] public point }.
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $ec = $details['ec'];
        self::assertIsArray($ec);
        $pad = static fn (mixed $value): string => str_pad(\is_string($value) ? $value : '', 32, "\0", \STR_PAD_LEFT);
        $body = "\x02\x01\x01\x04\x20" . $pad($ec['d'])
            . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
            . "\xa1\x44\x03\x42\x00\x04" . $pad($ec['x']) . $pad($ec['y']);
        $sec1 = "\x30" . pack('C', \strlen($body)) . $body;

        return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($sec1), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    }
}
