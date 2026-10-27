<?php

declare(strict_types=1);

namespace Polaris\Reading\Repository;

use Carbon\CarbonImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Polaris\Reading\Entity\VehicleDataSnapshot;
use Polaris\Vehicle\Entity\Vehicle;

/**
 * @extends ServiceEntityRepository<VehicleDataSnapshot>
 */
class VehicleDataSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VehicleDataSnapshot::class);
    }

    public function findAt(Vehicle $vehicle, CarbonImmutable $capturedAt): ?VehicleDataSnapshot
    {
        return $this->findOneBy(['vehicle' => $vehicle, 'capturedAt' => $capturedAt]);
    }
}
