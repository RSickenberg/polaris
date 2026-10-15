<?php

declare(strict_types=1);

namespace Polaris\Tesla\Domain;

/**
 * A secp256r1 (prime256v1) key pair as PEM text. The private key never leaves the process in
 * clear: it is hidden from dumps and serialization.
 */
final readonly class KeyPair
{
    public function __construct(
        public string $publicKeyPem,
        #[\SensitiveParameter]
        private string $privateKeyPem,
    ) {
    }

    public function privateKeyPem(): string
    {
        return $this->privateKeyPem;
    }

    /**
     * @return array{publicKeyPem: string}
     */
    public function __serialize(): array
    {
        return ['publicKeyPem' => $this->publicKeyPem];
    }

    /**
     * @return array{publicKeyPem: string}
     */
    public function __debugInfo(): array
    {
        return ['publicKeyPem' => $this->publicKeyPem];
    }
}
