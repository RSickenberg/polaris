<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tells whether this is a build in development: a local build (version "dev") or a nightly
 * image (version "nightly-<commit>", see .github/workflows/docker.yml). A release has neither,
 * so the debug page does not exist there.
 */
final readonly class PreviewBuild
{
    public const string NIGHTLY_PREFIX = 'nightly-';

    public function __construct(
        #[Autowire(env: 'default:polaris.version_fallback:SENTRY_RELEASE')]
        private string $version,
    ) {
    }

    public function version(): string
    {
        return $this->version;
    }

    public function isPreview(): bool
    {
        return 'dev' === $this->version || str_starts_with($this->version, self::NIGHTLY_PREFIX);
    }
}
