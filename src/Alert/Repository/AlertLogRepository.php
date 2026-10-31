<?php

declare(strict_types=1);

namespace Polaris\Alert\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\Persistence\ManagerRegistry;
use Polaris\Alert\Entity\AlertLog;
use Polaris\Vehicle\Entity\Vehicle;

/**
 * @extends ServiceEntityRepository<AlertLog>
 */
class AlertLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlertLog::class);
    }

    /**
     * The newest row of the vehicle: its level is the known one.
     *
     * @throws NonUniqueResultException
     */
    public function findLatest(Vehicle $vehicle): ?AlertLog
    {
        $log = $this->createQueryBuilder('a')
            ->where('a.vehicle = :vehicle')
            ->setParameter('vehicle', $vehicle->getId(), 'ulid')
            ->orderBy('a.sentAt', \SortDirection::Descending)
            ->addOrderBy('a.id', \SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $log instanceof AlertLog ? $log : null;
    }
}
