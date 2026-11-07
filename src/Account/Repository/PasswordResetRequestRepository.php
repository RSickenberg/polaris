<?php

declare(strict_types=1);

namespace Polaris\Account\Repository;

use Carbon\CarbonImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Polaris\Account\Entity\PasswordResetRequest;
use Polaris\Account\Entity\User;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * @extends ServiceEntityRepository<PasswordResetRequest>
 */
class PasswordResetRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetRequest::class);
    }

    public function findBySelector(string $selector): ?PasswordResetRequest
    {
        return $this->findOneBy(['selector' => $selector]);
    }

    public function findLatestFor(User $user): ?PasswordResetRequest
    {
        return $this->findOneBy(['user' => $user], ['requestedAt' => 'DESC']);
    }

    public function removeFor(User $user): void
    {
        $this->getEntityManager()->createQuery(\sprintf('DELETE %s r WHERE r.user = :user', PasswordResetRequest::class))
            ->setParameter('user', $user->getId(), UlidType::NAME)
            ->execute();
    }

    public function removeExpired(CarbonImmutable $now): void
    {
        $this->getEntityManager()->createQuery(\sprintf('DELETE %s r WHERE r.expiresAt <= :now', PasswordResetRequest::class))
            ->setParameter('now', $now->utc(), Types::DATETIME_IMMUTABLE)
            ->execute();
    }
}
