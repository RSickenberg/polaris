<?php

declare(strict_types=1);

namespace Polaris\Reading\Repository;

use Carbon\CarbonImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Vehicle\Entity\Vehicle;

/**
 * @extends ServiceEntityRepository<OdometerReading>
 */
class OdometerReadingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OdometerReading::class);
    }

    public function findAt(Vehicle $vehicle, CarbonImmutable $readAt): ?OdometerReading
    {
        return $this->findOneBy(['vehicle' => $vehicle, 'readAt' => $readAt]);
    }

    /**
     * The latest reading strictly before the instant.
     */
    public function findBefore(Vehicle $vehicle, CarbonImmutable $readAt): ?OdometerReading
    {
        $reading = $this->createQueryBuilder('r')
            ->where('r.vehicle = :vehicle')
            ->andWhere('r.readAt < :readAt')
            ->setParameter('vehicle', $vehicle->getId(), 'ulid')
            ->setParameter('readAt', $readAt, 'datetime_immutable')
            ->orderBy('r.readAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $reading instanceof OdometerReading ? $reading : null;
    }

    /**
     * The earliest reading strictly after the instant.
     */
    public function findAfter(Vehicle $vehicle, CarbonImmutable $readAt): ?OdometerReading
    {
        $reading = $this->createQueryBuilder('r')
            ->where('r.vehicle = :vehicle')
            ->andWhere('r.readAt > :readAt')
            ->setParameter('vehicle', $vehicle->getId(), 'ulid')
            ->setParameter('readAt', $readAt, 'datetime_immutable')
            ->orderBy('r.readAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $reading instanceof OdometerReading ? $reading : null;
    }

    /**
     * Every reading of the vehicle, newest first.
     *
     * @return list<OdometerReading>
     */
    public function findHistory(Vehicle $vehicle): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.vehicle = :vehicle')
            ->setParameter('vehicle', $vehicle->getId(), 'ulid')
            ->orderBy('r.readAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
