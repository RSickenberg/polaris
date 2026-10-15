<?php

declare(strict_types=1);

namespace Polaris\Account\EventListener;

use Polaris\Account\Entity\User;
use Polaris\Account\Service\UserPreferredLocaleProvider;
use Polaris\Account\Service\UserSettings;
use Polaris\Shared\Event\LocaleSwitched;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Keeps the language the signed-in user picked with the switcher as their preference.
 */
#[AsEventListener]
final readonly class SaveSwitchedLocale
{
    public function __construct(
        private Security $security,
        private UserSettings $preferences,
        private RequestStack $requestStack,
    ) {
    }

    public function __invoke(LocaleSwitched $event): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $this->preferences->changeLocale($user, $event->locale);
        $this->requestStack->getSession()->set(UserPreferredLocaleProvider::SESSION_KEY, $event->locale);
    }
}
