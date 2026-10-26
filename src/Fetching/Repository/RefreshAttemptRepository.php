<?php

declare(strict_types=1);

namespace Polaris\Fetching\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Polaris\Fetching\Entity\RefreshAttempt;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Component\Uid\Ulid;

/**
 * @extends ServiceEntityRepository<RefreshAttempt>
 */
class RefreshAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshAttempt::class);
    }

    /**
     * An attempt of this vehicle only: an id of another vehicle's attempt finds nothing.
     */
    public function findOf(Vehicle $vehicle, string $id): ?RefreshAttempt
    {
        try {
            $ulid = Ulid::fromString($id);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->findOneBy(['vehicle' => $vehicle, 'id' => $ulid]);
    }
}
