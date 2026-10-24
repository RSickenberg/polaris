<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Dto;

use Polaris\Tesla\Service\Enum\PublicKeyCheckStatus;

/**
 * The outcome of fetching the public key from the app domain, with a message fit for the console.
 */
final readonly class PublicKeyCheckResult
{
    private function __construct(
        public PublicKeyCheckStatus $status,
        public string $message,
    ) {
    }

    public static function matches(string $url, ?string $teslaUrl = null): self
    {
        $message = \sprintf('%s serves the public key stored in Polaris.', $url);

        if (null !== $teslaUrl && $teslaUrl !== $url) {
            $message .= \sprintf(' Tesla itself reads %s: make sure that URL serves the same key.', $teslaUrl);
        }

        return new self(PublicKeyCheckStatus::Match, $message);
    }

    public static function mismatch(string $url): self
    {
        return new self(
            PublicKeyCheckStatus::Mismatch,
            \sprintf('%s serves a different key than the stored key pair. Publish the current public key there.', $url),
        );
    }

    public static function notFound(string $url, int $httpStatus): self
    {
        return new self(
            PublicKeyCheckStatus::NotFound,
            \sprintf('%s answered HTTP %d instead of the public key.', $url, $httpStatus),
        );
    }

    public static function redirected(string $url, int $httpStatus): self
    {
        return new self(
            PublicKeyCheckStatus::Redirected,
            \sprintf('%s answered a redirect (HTTP %d). Serve the key directly on that URL; redirects are not followed.', $url, $httpStatus),
        );
    }

    public static function unreachable(string $url, string $reason): self
    {
        return new self(PublicKeyCheckStatus::Unreachable, \sprintf('%s could not be fetched: %s', $url, $reason));
    }

    public static function notConfigured(): self
    {
        return new self(
            PublicKeyCheckStatus::NotConfigured,
            'The app domain is not set yet. Store it with polaris:tesla:configure.',
        );
    }

    public function isMatch(): bool
    {
        return PublicKeyCheckStatus::Match === $this->status;
    }
}
