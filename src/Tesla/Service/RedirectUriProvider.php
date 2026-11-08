<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The OAuth redirect URI, sent unchanged to /authorize and /token (Tesla rejects a mismatch with
 * invalid_redirect_url). POLARIS_TESLA_REDIRECT_URI sets it; without it, it is DEFAULT_URI followed by
 * the callback path, without the default port of its scheme (a browser shows https://host, not
 * https://host:443). It must be listed in the allowed redirect URIs of the Tesla developer app.
 */
final readonly class RedirectUriProvider
{
    public const string CALLBACK_PATH = '/tesla/callback';

    public function __construct(
        #[Autowire(env: 'POLARIS_TESLA_REDIRECT_URI')]
        private string $configured,
        #[Autowire(env: 'DEFAULT_URI')]
        private string $defaultUri,
    ) {
    }

    public function get(): string
    {
        $configured = trim($this->configured);

        if ('' !== $configured) {
            return $configured;
        }

        $base = rtrim(trim($this->defaultUri), '/');
        $base = (string) preg_replace('#^(https://[^/]+):443$#', '$1', $base);
        $base = (string) preg_replace('#^(http://[^/]+):80$#', '$1', $base);

        return $base . self::CALLBACK_PATH;
    }
}
