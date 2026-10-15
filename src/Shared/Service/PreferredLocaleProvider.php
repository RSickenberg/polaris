<?php

declare(strict_types=1);

namespace Polaris\Shared\Service;

/**
 * The locale the current user chose, if any. It wins over the browser's Accept-Language.
 * The Account module decorates the cookie implementation with the language of the signed-in user.
 */
interface PreferredLocaleProvider
{
    public function preferredLocale(): ?string;
}
