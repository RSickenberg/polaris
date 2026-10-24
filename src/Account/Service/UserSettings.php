<?php

declare(strict_types=1);

namespace Polaris\Account\Service;

use Doctrine\ORM\EntityManagerInterface;
use Polaris\Account\Entity\User;
use Polaris\Account\Repository\PasswordResetRequestRepository;
use Polaris\Account\Repository\UserRepository;
use Polaris\Account\Service\Dto\NewUser;
use Polaris\Account\Service\Exception\UserSettingsRejected;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Changes the settings of the user: email, language, time zone and password. The caller signs
 * the user in again afterward, as the session of a changed email or password is no longer valid.
 */
final readonly class UserSettings
{
    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private PasswordResetRequestRepository $resetRequests,
        private UserPasswordHasherInterface $hasher,
        #[Autowire('%kernel.enabled_locales%')]
        private array $enabledLocales,
    ) {
    }

    /**
     * @throws UserSettingsRejected      when another user already has the email
     * @throws \InvalidArgumentException when the email, the locale or the time zone is not valid
     */
    public function updateProfile(User $user, string $email, string $locale, string $timeZone): void
    {
        $normalized = User::normalizeEmail($email);
        $owner = $this->users->loadUserByIdentifier($normalized);
        if (null !== $owner && !$owner->getId()->equals($user->getId())) {
            throw UserSettingsRejected::emailTaken();
        }

        $this->assertEnabled($locale);
        // The setters validate, and the flush comes last: a refusal persists nothing.
        $user->changeTimeZone($timeZone);
        $user->changeEmail($normalized);
        $user->changeLocale($locale);
        $this->entityManager->flush();
    }

    /**
     * @throws \InvalidArgumentException when the locale is not one of the enabled ones
     */
    public function changeLocale(User $user, string $locale): void
    {
        $this->assertEnabled($locale);

        $user->changeLocale($locale);
        $this->entityManager->flush();
    }

    /**
     * @throws \InvalidArgumentException when the password is too short or too long
     */
    public function changePassword(User $user, #[\SensitiveParameter] string $plainPassword): void
    {
        $length = mb_strlen($plainPassword);
        if ($length < NewUser::PASSWORD_MIN_LENGTH || $length > NewUser::PASSWORD_MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A password must have %d to %d characters.', NewUser::PASSWORD_MIN_LENGTH, NewUser::PASSWORD_MAX_LENGTH));
        }

        $user->changePassword($this->hasher->hashPassword($user, $plainPassword));
        // A reset link is worth nothing once the password is changed, by whoever did it.
        $this->resetRequests->removeFor($user);
        $this->entityManager->flush();
    }

    private function assertEnabled(string $locale): void
    {
        if (!\in_array($locale, $this->enabledLocales, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an enabled locale.', $locale));
        }
    }
}
