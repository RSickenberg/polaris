<?php

declare(strict_types=1);

namespace Polaris\Extension;

use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The key is hosted on a static site (for example GitHub Pages). Polaris says where it is in one of
 * two ways, or both:
 *
 * - a directory: the public key is written to <directory>/.well-known/appspecific/com.tesla.3p.public-key.pem,
 *   in a local clone of the site. Committing and pushing stay manual;
 * - a URL of the PEM file: nothing is written, the key is published by hand and the check reads that URL.
 *
 * Polaris itself does not answer the well-known URL.
 */
final readonly class ExternalPublicKeyHosting implements PublicKeyHosting
{
    public const string RELATIVE_PATH = '.well-known/appspecific/com.tesla.3p.public-key.pem';

    /**
     * @throws \InvalidArgumentException when the URL is not an https URL of a file
     */
    public function __construct(
        private Filesystem $filesystem,
        private string $directory,
        private string $url = '',
    ) {
        if ('' !== $url) {
            $parts = parse_url($url);

            if (false === $parts || 'https' !== ($parts['scheme'] ?? null) || '' === ($parts['host'] ?? '') || '' === ($parts['path'] ?? '') || isset($parts['query']) || isset($parts['fragment'])) {
                throw new \InvalidArgumentException('POLARIS_KEY_HOSTING_URL must be the https URL of the PEM file, without query or fragment, for example https://polaris.example.com/.well-known/appspecific/com.tesla.3p.public-key.pem.');
            }
        }
    }

    public function publish(string $publicKeyPem): void
    {
        if ('' === $this->directory && '' === $this->url) {
            throw new \RuntimeException('Set POLARIS_KEY_HOSTING_DIR to the directory of the static site, or POLARIS_KEY_HOSTING_URL to the URL of the PEM file, when POLARIS_KEY_HOSTING is "external".');
        }

        // Only a URL: the key is published by hand, there is nothing to write.
        if ('' === $this->directory) {
            return;
        }

        try {
            $this->filesystem->dumpFile(rtrim($this->directory, '/') . '/' . self::RELATIVE_PATH, $publicKeyPem);
        } catch (IOExceptionInterface $exception) {
            throw new \RuntimeException('The public key could not be written to the static site directory: ' . $exception->getMessage(), 0, $exception);
        }
    }

    public function servesPublicKey(): bool
    {
        return false;
    }

    public function publicKeyUrl(): ?string
    {
        return '' === $this->url ? null : $this->url;
    }
}
