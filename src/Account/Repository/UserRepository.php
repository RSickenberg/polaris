<?php

declare(strict_types=1);

namespace Polaris\Account\Repository;

use Carbon\CarbonImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\Persistence\ManagerRegistry;
use Polaris\Account\Entity\User;
use Polaris\Shared\Doctrine\Type\CarbonDateImmutableType;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface, UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * The email is compared the way it is stored, trimmed and lower-cased.
     */
    public function loadUserByIdentifier(string $identifier): ?User
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($identifier))]);
    }

    /**
     * @throws ORMException
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->changePassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }

    /**
     * Claims the midnight jobs of a local day: moves `lastMidnightDay` to $day only if it is older (or never set), in one
     * UPDATE, so of two writers exactly one gets true.
     */
    public function claimMidnight(User $user, CarbonImmutable $day): bool
    {
        return 1 === (int) $this->createQueryBuilder('u')
            ->update()
            ->set('u.lastMidnightDay', ':day')
            ->where('u.id = :id')
            ->andWhere('u.lastMidnightDay IS NULL OR u.lastMidnightDay < :day')
            ->setParameter('day', $day, CarbonDateImmutableType::NAME)
            ->setParameter('id', $user->getId(), 'ulid')
            ->getQuery()
            ->execute();
    }

    /**
     * Gives a claim back when its message could not be queued: only if nobody moved the day since.
     */
    public function releaseMidnight(User $user, CarbonImmutable $claimed, ?CarbonImmutable $previous): void
    {
        $this->createQueryBuilder('u')
            ->update()
            ->set('u.lastMidnightDay', ':previous')
            ->where('u.id = :id')
            ->andWhere('u.lastMidnightDay = :claimed')
            ->setParameter('previous', $previous, CarbonDateImmutableType::NAME)
            ->setParameter('claimed', $claimed, CarbonDateImmutableType::NAME)
            ->setParameter('id', $user->getId(), 'ulid')
            ->getQuery()
            ->execute();
    }
}
