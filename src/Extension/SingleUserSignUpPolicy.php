<?php

declare(strict_types=1);

namespace Polaris\Extension;

use Doctrine\ORM\EntityManagerInterface;
use Polaris\Account\Entity\User;
use Polaris\Account\Service\NewUser;
use Polaris\Account\Service\UserCreationRejected;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * A self-hosted Polaris has a single user.
 */
#[AsAlias(SignUpPolicy::class)]
final readonly class SingleUserSignUpPolicy implements SignUpPolicy
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function assertCanSignUp(NewUser $newUser): void
    {
        if ($this->entityManager->getRepository(User::class)->count() > 0) {
            throw UserCreationRejected::alreadyExists();
        }
    }
}
