<?php

declare(strict_types=1);

namespace Polaris\Shared\Service;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * No user preference exists yet, so the locale comes from the browser (#40). The Account
 * module replaces this alias with a provider that reads User.locale (#21).
 */
#[AsAlias(PreferredLocaleProvider::class)]
final class NullPreferredLocaleProvider implements PreferredLocaleProvider
{
    public function preferredLocale(): ?string
    {
        return null;
    }
}
