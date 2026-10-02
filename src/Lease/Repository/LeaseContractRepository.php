<?php

declare(strict_types=1);

namespace Polaris\Lease\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Vehicle\Entity\Vehicle;

/**
 * @extends ServiceEntityRepository<LeaseContract>
 */
class LeaseContractRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeaseContract::class);
    }

    public function findForVehicle(Vehicle $vehicle): ?LeaseContract
    {
        return $this->findOneBy(['vehicle' => $vehicle]);
    }
}
