<?php

declare(strict_types=1);

namespace Polaris\Extension;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The self-hosted default: POLARIS_KEY_HOSTING picks "polaris" (default) or "external", which also
 * needs POLARIS_KEY_HOSTING_DIR (Polaris writes the file) or POLARIS_KEY_HOSTING_URL (the URL of the
 * PEM file, published by hand), or both.
 */
#[AsAlias(PublicKeyHosting::class)]
final readonly class ConfiguredPublicKeyHosting implements PublicKeyHosting
{
    private PublicKeyHosting $inner;

    public function __construct(
        Filesystem $filesystem,
        #[Autowire(env: 'enum:Polaris\Extension\PublicKeyHostingMode:POLARIS_KEY_HOSTING')]
        PublicKeyHostingMode $mode,
        #[Autowire(env: 'POLARIS_KEY_HOSTING_DIR')]
        string $directory,
        #[Autowire(env: 'POLARIS_KEY_HOSTING_URL')]
        string $url = '',
    ) {
        $this->inner = match ($mode) {
            PublicKeyHostingMode::Polaris => new PolarisPublicKeyHosting(),
            PublicKeyHostingMode::External => new ExternalPublicKeyHosting($filesystem, $directory, $url),
        };
    }

    public function publish(string $publicKeyPem): void
    {
        $this->inner->publish($publicKeyPem);
    }

    public function servesPublicKey(): bool
    {
        return $this->inner->servesPublicKey();
    }

    public function publicKeyUrl(): ?string
    {
        return $this->inner->publicKeyUrl();
    }
}
