<?php

declare(strict_types=1);

namespace Polaris\Shared\Service;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The locale picked with the language switcher, kept in a cookie until users have a stored
 * preference. The Account module replaces this alias with a provider that reads User.locale
 * (#21). The value is not trusted here: the locale negotiation ignores an unsupported one.
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
