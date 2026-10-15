<?php

declare(strict_types=1);

namespace Polaris\Shared\Event;

/**
 * The visitor picked a language with the switcher. The Account module stores it as the
 * preference of the signed-in user.
 */
final readonly class LocaleSwitched
{
    public function __construct(public string $locale)
    {
    }
}
