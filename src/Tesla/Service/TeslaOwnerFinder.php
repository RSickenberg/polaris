<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service;

use Doctrine\ORM\EntityManagerInterface;
use Polaris\Account\Entity\User;

/**
 * Finds the user a Tesla console command acts for: the one with the given email, or the only user
 * when no email is given.
 */
final readonly class TeslaOwnerFinder
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(?string $email): ?User
    {
        $users = $this->entityManager->getRepository(User::class);

        if (null !== $email) {
            return $users->findOneBy(['email' => User::normalizeEmail($email)]);
        }

        $all = $users->findBy([], null, 2);

        return 1 === \count($all) ? $all[0] : null;
    }
}
