<?php

declare(strict_types=1);

namespace Polaris\Account\Service;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Account\Entity\User;
use Polaris\Account\Service\Dto\NewUser;
use Polaris\Account\Service\Exception\UserCreationRejected;
use Polaris\Extension\SignUpPolicy;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The only place that creates the user, so that the command, and any other front end, apply the
 * same rules: a valid email and password, and the sign-up policy (a single user per self-hosted
 * installation). The hosted layer replaces the `SignUpPolicy` to apply its own.
 */
readonly class UserCreator
{
    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SignUpPolicy $signUpPolicy,
        private UserPasswordHasherInterface $hasher,
        private ValidatorInterface $validator,
        private ClockInterface $clock,
        #[Autowire('%kernel.enabled_locales%')]
        private array $enabledLocales,
    ) {
    }

    /**
     * Creates the user.
     *
     * @throws UserCreationRejected      when the sign-up policy refuses the user
     * @throws \InvalidArgumentException when the email or the password is not valid
     * @throws ORMException
     */
    public function create(NewUser $newUser): User
    {
        $violations = $this->validator->validate($newUser);
        if (\count($violations) > 0) {
            throw new \InvalidArgumentException(implode(' ', array_map(static fn ($violation): string => \sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage()), iterator_to_array($violations))));
        }

        if (null !== $newUser->locale && !\in_array($newUser->locale, $this->enabledLocales, true)) {
            throw new \InvalidArgumentException(\sprintf('locale: "%s" is not an enabled locale (%s).', $newUser->locale, implode(', ', $this->enabledLocales)));
        }

        $this->signUpPolicy->assertCanSignUp($newUser);

        $user = new User($newUser->email, '', CarbonImmutable::instance($this->clock->now()));
        $user->changePassword($this->hasher->hashPassword($user, $newUser->plainPassword));
        if (null !== $newUser->locale) {
            $user->changeLocale($newUser->locale);
        }
        $user->changeTimeZone($newUser->timeZone);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
