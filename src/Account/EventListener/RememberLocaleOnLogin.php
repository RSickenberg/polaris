<?php

declare(strict_types=1);

namespace Polaris\Account\EventListener;

use Polaris\Account\Entity\User;
use Polaris\Account\Service\UserPreferredLocaleProvider;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Puts the language of the user who just signed in in the session, where the locale
 * negotiation finds it (see {@see UserPreferredLocaleProvider}).
 */
#[AsEventListener]
final class RememberLocaleOnLogin
{
    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        if ($user instanceof User && $event->getRequest()->hasSession()) {
            $event->getRequest()->getSession()->set(UserPreferredLocaleProvider::SESSION_KEY, $user->getLocale());
        }
    }
}
