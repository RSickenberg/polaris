<?php

declare(strict_types=1);

namespace Polaris\Lease\Repository;

use Carbon\CarbonImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Lease\Entity\ProjectionSnapshot;
use Polaris\Shared\Doctrine\Type\CarbonDateImmutableType;

/**
 * @extends ServiceEntityRepository<ProjectionSnapshot>
 */
class ProjectionSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectionSnapshot::class);
    }

    public function findForDay(LeaseContract $contract, CarbonImmutable $localDay): ?ProjectionSnapshot
    {
        return $this->findOneBy(['leaseContract' => $contract, 'localDay' => $localDay]);
    }

    /**
     * The snapshots of the lease, oldest local day first.
     *
     * @param CarbonImmutable|null $from first local day, inclusive
     * @param CarbonImmutable|null $to   last local day, inclusive
     *
     * @return list<ProjectionSnapshot>
     */
    public function findHistory(LeaseContract $contract, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $query = $this->createQueryBuilder('s')
            ->andWhere('s.leaseContract = :contract')
            ->setParameter('contract', $contract->getId(), 'ulid')
            ->orderBy('s.localDay', \SortDirection::Ascending);

        if (null !== $from) {
            $query->andWhere('s.localDay >= :from')->setParameter('from', self::calendarDate($from), CarbonDateImmutableType::NAME);
        }

        if (null !== $to) {
            $query->andWhere('s.localDay <= :to')->setParameter('to', self::calendarDate($to), CarbonDateImmutableType::NAME);
        }

        /* @var list<ProjectionSnapshot> */
        return $query->getQuery()->getResult();
    }

    /**
     * The calendar date of $date as written (its own Y-m-d, no conversion), as midnight UTC, the way a day is stored.
     */
    private static function calendarDate(CarbonImmutable $date): CarbonImmutable
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date->format('Y-m-d'), 'UTC');
        \assert($day instanceof CarbonImmutable);

        return $day;
    }
}
