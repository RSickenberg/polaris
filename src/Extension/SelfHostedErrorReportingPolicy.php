<?php

declare(strict_types=1);

namespace Polaris\Extension;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * A self-hosted instance only reports errors when its owner turned it on, so the user starts opted in.
 */
#[AsAlias(ErrorReportingPolicy::class)]
final readonly class SelfHostedErrorReportingPolicy implements ErrorReportingPolicy
{
    public function defaultForNewUser(): bool
    {
        return true;
    }
}
