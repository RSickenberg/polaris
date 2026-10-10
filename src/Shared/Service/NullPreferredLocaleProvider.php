<?php

declare(strict_types=1);

namespace Polaris\Shared\Service;

/**
 * No preference: the locale comes from the browser (#40). Not wired in the application, which
 * uses CookiePreferredLocaleProvider; kept as the neutral implementation for tests.
 */
final class NullPreferredLocaleProvider implements PreferredLocaleProvider
{
    public function preferredLocale(): ?string
    {
        return null;
    }
}
