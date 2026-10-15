<?php

declare(strict_types=1);

namespace Polaris\Extension;

/**
 * Polaris answers the well-known URL itself, from the key stored on the Tesla app.
 */
final readonly class PolarisPublicKeyHosting implements PublicKeyHosting
{
    public function publish(string $publicKeyPem): void
    {
    }

    public function servesPublicKey(): bool
    {
        return true;
    }

    public function publicKeyUrl(): ?string
    {
        return null;
    }
}
