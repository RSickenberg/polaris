<?php

declare(strict_types=1);

namespace Polaris\Extension;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpFoundation\Request;

/**
 * A self-hosted Polaris opens no page of its own choice: nothing matches.
 */
#[AsAlias(PublicRequestMatcher::class)]
final readonly class ClosedPublicRequestMatcher implements PublicRequestMatcher
{
    public function matches(Request $request): bool
    {
        return false;
    }
}
