<?php

declare(strict_types=1);

namespace Polaris\Lease\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

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

    /**
     * The contracts of the vehicles of one owner.
     *
     * @return list<LeaseContract>
     */
    public function findOfOwner(Ulid $ownerId): array
    {
        /** @var list<LeaseContract> $contracts */
        $contracts = $this->createQueryBuilder('c')
            ->join('c.vehicle', 'v')
            ->where('v.owner = :owner')
            ->setParameter('owner', $ownerId, UlidType::NAME)
            ->getQuery()
            ->getResult();

        return $contracts;
    }
}
