<?php

declare(strict_types=1);

namespace Polaris\Fetching\Repository;

use Carbon\CarbonImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Polaris\Fetching\Entity\FetchSlotRun;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * @extends ServiceEntityRepository<FetchSlotRun>
 */
class FetchSlotRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FetchSlotRun::class);
    }

    public function findAt(Vehicle $vehicle, CarbonImmutable $slotAt): ?FetchSlotRun
    {
        return $this->findOneBy(['vehicle' => $vehicle, 'slotAt' => $slotAt]);
    }

    /**
     * The slots of the vehicle that still have checks queued.
     *
     * @return list<FetchSlotRun>
     */
    public function findOpen(Vehicle $vehicle): array
    {
        return array_values(array_filter(
            $this->findBy(['vehicle' => $vehicle]),
            static fn (FetchSlotRun $run): bool => $run->getStatus()->isOpen(),
        ));
    }

    /**
     * @return int how many rows were deleted
     */
    public function deleteSlotsBefore(Vehicle $vehicle, CarbonImmutable $before): int
    {
        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->where('r.vehicle = :vehicle')
            ->andWhere('r.slotAt < :before')
            ->setParameter('vehicle', $vehicle->getId(), UlidType::NAME)
            ->setParameter('before', $before, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->execute();
    }
}
