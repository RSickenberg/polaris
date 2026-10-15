<?php

declare(strict_types=1);

namespace Polaris\Tesla\Domain;

/**
 * Builds a {@see KeyPair} from an existing private key (PKCS8 or SEC1 PEM, not passphrase protected).
 * The public key is derived from it, so the two always match. Only prime256v1 is accepted, the one
 * curve Tesla supports; the private key is exported again as an unencrypted PKCS8 PEM, like the keys
 * Polaris generates.
 */
final class KeyPairImporter
{
    /**
     * @throws InvalidKeyPair
     */
    public function fromPrivateKey(#[\SensitiveParameter] string $privatePem): KeyPair
    {
        if (!str_contains($privatePem, 'PRIVATE KEY-----')) {
            throw new InvalidKeyPair('The input is not a PEM private key.');
        }

        if (str_contains($privatePem, 'ENCRYPTED')) {
            throw new InvalidKeyPair('The private key is protected by a passphrase, which is not supported. Remove the passphrase first.');
        }

        // Without a passphrase argument OpenSSL may try to prompt: an empty one makes it fail instead.
        $key = @openssl_pkey_get_private($privatePem, '');

        if (false === $key) {
            throw new InvalidKeyPair('The private key cannot be read.');
        }

        $details = openssl_pkey_get_details($key);

        if (false === $details || \OPENSSL_KEYTYPE_EC !== ($details['type'] ?? null)) {
            throw new InvalidKeyPair('The private key is not an elliptic curve key.');
        }

        $curve = \is_array($details['ec'] ?? null) ? ($details['ec']['curve_name'] ?? null) : null;

        if ('prime256v1' !== $curve) {
            throw new InvalidKeyPair('The private key must use the prime256v1 curve (also called secp256r1), the only one Tesla supports.');
        }

        if (!openssl_pkey_export($key, $pkcs8) || !\is_string($pkcs8) || !\is_string($details['key'] ?? null)) {
            throw new InvalidKeyPair('The private key cannot be exported.');
        }

        return new KeyPair($details['key'], $pkcs8);
    }
}
