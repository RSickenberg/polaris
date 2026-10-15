<?php

declare(strict_types=1);

namespace Polaris\Tesla\Domain;

/**
 * Generates the key pair Tesla expects: the vehicle only supports prime256v1 keys. The private key
 * is exported as an unencrypted PKCS8 PEM ("BEGIN PRIVATE KEY"), the public key as a PKIX PEM
 * ("BEGIN PUBLIC KEY").
 */
final class KeyPairGenerator
{
    public function generate(): KeyPair
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => \OPENSSL_KEYTYPE_EC,
        ]);

        if (false === $key || !openssl_pkey_export($key, $privatePem)) {
            throw new \RuntimeException('OpenSSL could not generate a prime256v1 key pair.');
        }

        $details = openssl_pkey_get_details($key);

        if (false === $details || !\is_string($details['key'] ?? null) || !\is_string($privatePem)) {
            throw new \RuntimeException('OpenSSL could not export the key pair.');
        }

        return new KeyPair($details['key'], $privatePem);
    }
}
