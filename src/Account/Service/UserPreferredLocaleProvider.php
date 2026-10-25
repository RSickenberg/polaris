<?php

declare(strict_types=1);

namespace Polaris\Account\Service;

use Polaris\Shared\Service\PreferredLocaleProvider;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The language of the signed-in user, falling back to the cookie of the switcher.
 *
 * The locale is negotiated on kernel.request at priority 20, before the firewall (priority 8)
 * has put the user in the token storage. So the user's locale is copied into the session when
 * they sign in or switch language, and read from there: no user is loaded before the firewall.
 * Signing out invalidates the session, which brings back the cookie.
 */
#[AsDecorator(decorates: PreferredLocaleProvider::class)]
final readonly class UserPreferredLocaleProvider implements PreferredLocaleProvider
{
    public const string SESSION_KEY = 'polaris.locale';

    public function __construct(
        #[AutowireDecorated]
        private PreferredLocaleProvider $fallback,

        private RequestStack $requestStack,
    ) {
    }

    public function preferredLocale(): ?string
    {
        $request = $this->requestStack->getMainRequest();

        // Looking at the session only when the browser sent its cookie avoids starting one for nothing.
        if (null !== $request && $request->hasSession() && $request->hasPreviousSession()) {
            $locale = $request->getSession()->get(self::SESSION_KEY);
            if (\is_string($locale)) {
                return $locale;
            }
        }

        return $this->fallback->preferredLocale();
    }
}
