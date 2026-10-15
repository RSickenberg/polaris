<?php

declare(strict_types=1);

namespace Polaris\Shared\Service;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The locale picked with the language switcher, kept in a cookie. It serves visitors who are
 * not signed in; the Account module decorates it with the stored preference of the signed-in
 * user. The value is not trusted here: the locale negotiation ignores an unsupported one.
 */
#[AsAlias(PreferredLocaleProvider::class)]
final readonly class CookiePreferredLocaleProvider implements PreferredLocaleProvider
{
    public const string COOKIE = 'polaris_locale';

    public function __construct(private RequestStack $requestStack)
    {
    }

    public function preferredLocale(): ?string
    {
        $value = $this->requestStack->getMainRequest()?->cookies->all()[self::COOKIE] ?? null;

        return \is_string($value) ? $value : null;
    }
}
