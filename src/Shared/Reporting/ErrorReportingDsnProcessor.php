<?php

declare(strict_types=1);

namespace Polaris\Shared\Reporting;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * Turns the switch of the owner into the DSN the Sentry SDK gets: `%env(polaris_dsn:POLARIS_ERROR_REPORTING)%`.
 *
 * - `maintainer`: the DSN shipped with the image (`polaris.sentry.maintainer_dsn`), so the maintainer can rotate it.
 * - `custom`: the owner's own `SENTRY_DSN`.
 * - anything else, empty or unset: no DSN, so the SDK sends nothing and opens no connection.
 */
final readonly class ErrorReportingDsnProcessor implements EnvVarProcessorInterface
{
    public function __construct(
        #[Autowire('%polaris.sentry.maintainer_dsn%')]
        private string $maintainerDsn,
    ) {
    }

    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        $mode = ErrorReportingMode::fromSwitch($this->read($name, $getEnv));

        return match ($mode) {
            ErrorReportingMode::Maintainer => trim($this->maintainerDsn),
            ErrorReportingMode::Custom => trim($this->read('SENTRY_DSN', $getEnv)),
            ErrorReportingMode::Off => '',
        };
    }

    public static function getProvidedTypes(): array
    {
        return ['polaris_dsn' => 'string'];
    }

    private function read(string $name, \Closure $getEnv): string
    {
        try {
            $value = $getEnv($name);
        } catch (\Throwable) {
            return '';
        }

        return \is_string($value) ? $value : '';
    }
}
