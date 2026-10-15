<?php

declare(strict_types=1);

namespace Polaris\Extension;

use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The key is hosted on a static site (for example GitHub Pages): the public key is written to
 * <directory>/.well-known/appspecific/com.tesla.3p.public-key.pem, in a local clone of the site.
 * Committing and pushing stay manual. Polaris itself does not answer the URL.
 */
final readonly class ExternalPublicKeyHosting implements PublicKeyHosting
{
    public const string RELATIVE_PATH = '.well-known/appspecific/com.tesla.3p.public-key.pem';

    public function __construct(
        private Filesystem $filesystem,
        private string $directory,
    ) {
    }

    public function publish(string $publicKeyPem): void
    {
        if ('' === $this->directory) {
            throw new \RuntimeException('POLARIS_KEY_HOSTING_DIR must be set to the directory of the static site when POLARIS_KEY_HOSTING is "external".');
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
}
