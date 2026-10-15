<?php

declare(strict_types=1);

namespace Polaris\Shared\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts the secrets stored in the database (Tesla client secret, private key, tokens): libsodium
 * secretbox, XSalsa20-Poly1305 with a random nonce (ADR 0007).
 *
 * The ciphertext is "v1:" followed by the base64 of nonce and box. The prefix names the format, so a
 * later version can change the algorithm or carry a key identifier to rotate the key while old values
 * stay readable.
 *
 * The key is 32 random bytes in base64 (openssl rand -base64 32), taken from the environment variable
 * POLARIS_ENCRYPTION_KEY, which a Symfony secret can provide.
 */
final readonly class SecretEncrypter
{
    private const string VERSION = 'v1';

    private string $key;

    public function __construct(
        #[Autowire(env: 'POLARIS_ENCRYPTION_KEY')]
        #[\SensitiveParameter]
        string $encryptionKey,
    ) {
        $key = base64_decode($encryptionKey, true);

        if (false === $key || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
            throw new \InvalidArgumentException('POLARIS_ENCRYPTION_KEY must be 32 random bytes encoded in base64 (openssl rand -base64 32).');
        }

        $this->key = $key;
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return self::VERSION . ':' . base64_encode($nonce . $box);
    }

    /**
     * @throws DecryptionFailed
     */
    public function decrypt(string $ciphertext): string
    {
        [$version, $payload] = explode(':', $ciphertext, 2) + [1 => ''];
        $decoded = self::VERSION === $version ? base64_decode($payload, true) : false;

        if (false === $decoded || \strlen($decoded) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new DecryptionFailed('The stored secret has an unknown format.');
        }

        $plaintext = sodium_crypto_secretbox_open(
            substr($decoded, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($decoded, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );

        if (false === $plaintext) {
            throw new DecryptionFailed('The stored secret cannot be decrypted: wrong key or altered value.');
        }

        return $plaintext;
    }
}
