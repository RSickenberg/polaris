<?php

declare(strict_types=1);

namespace Polaris\Tests\Support;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Polaris\Account\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Creates the user (once per email) and signs the test client in, without going through the login form.
 * The email and the hash are fake: the password is never checked on this path.
 */
trait SignsIn
{
    private static function createUser(string $email = 'driver@example.test'): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $existing = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing instanceof User) {
            return $existing;
        }

        $user = new User($email, 'not-a-real-hash', CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'));
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private static function signIn(KernelBrowser $client, ?User $user = null): User
    {
        $user ??= self::createUser();
        $client->loginUser($user);

        return $user;
    }
}
