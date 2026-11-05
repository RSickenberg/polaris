<?php

declare(strict_types=1);

namespace Polaris\Lease\Repository;

use Carbon\CarbonImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Lease\Entity\ProjectionSnapshot;

/**
 * @extends ServiceEntityRepository<ProjectionSnapshot>
 */
class ProjectionSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectionSnapshot::class);
    }

    public function findForDay(LeaseContract $contract, CarbonImmutable $day): ?ProjectionSnapshot
    {
        return $this->findOneBy(['leaseContract' => $contract, 'day' => $day]);
    }

    /**
     * The snapshots of the lease, oldest day first.
     *
     * @param CarbonImmutable|null $from first day, inclusive
     * @param CarbonImmutable|null $to   last day, inclusive
     *
     * @return list<ProjectionSnapshot>
     */
    public function findHistory(LeaseContract $contract, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $query = $this->createQueryBuilder('s')
            ->andWhere('s.leaseContract = :contract')
            ->setParameter('contract', $contract->getId(), 'ulid')
            ->orderBy('s.day', \SortDirection::Ascending);

        if (null !== $from) {
            $query->andWhere('s.day >= :from')->setParameter('from', $from->utc()->startOfDay(), 'carbon_date_immutable');
        }

        if (null !== $to) {
            $query->andWhere('s.day <= :to')->setParameter('to', $to->utc()->startOfDay(), 'carbon_date_immutable');
        }

        /* @var list<ProjectionSnapshot> */
        return $query->getQuery()->getResult();
    }
}
