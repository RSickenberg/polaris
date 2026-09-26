<?php

declare(strict_types=1);

namespace Polaris;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Polaris stores, computes and exchanges every date in UTC.
     *
     * The runtime time zone is forced at boot so that web requests, console commands
     * and Messenger workers behave the same even if php.ini says otherwise.
     */
    public const string TIMEZONE = 'UTC';

    /**
     * Builds the kernel from the symfony/runtime context ($_SERVER merged with $_ENV).
     *
     * The runtime always sets APP_ENV and APP_DEBUG, but the context is untyped: the
     * environment is checked here so a broken setup fails loudly instead of booting
     * an unexpected environment.
     *
     * @param array<mixed> $context
     */
    public static function fromContext(array $context): self
    {
        $environment = $context['APP_ENV'] ?? null;

        if (!\is_string($environment) || '' === $environment) {
            throw new \LogicException('APP_ENV must be a non-empty string.');
        }

        return new self($environment, (bool) ($context['APP_DEBUG'] ?? false));
    }

    public function boot(): void
    {
        date_default_timezone_set(self::TIMEZONE);

        parent::boot();
    }
}
