<?php

declare(strict_types=1);

namespace Polaris\Account\Service;

use Doctrine\ORM\EntityManagerInterface;
use Polaris\Account\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Changes the display preferences of the user: the language and the time zone.
 */
final readonly class UserPreferences
{
    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire('%kernel.enabled_locales%')]
        private array $enabledLocales,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the locale is not one of the enabled ones
     */
    public function changeLocale(User $user, string $locale): void
    {
        if (!\in_array($locale, $this->enabledLocales, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an enabled locale.', $locale));
        }

        $user->changeLocale($locale);
        $this->entityManager->flush();
    }

    /**
     * @throws \InvalidArgumentException when it is not an IANA time zone
     */
    public function changeTimeZone(User $user, string $timeZone): void
    {
        $user->changeTimeZone($timeZone);
        $this->entityManager->flush();
    }
}
