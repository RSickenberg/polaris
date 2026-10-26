<?php

declare(strict_types=1);

namespace Polaris\Fetching\Repository;

use Carbon\CarbonImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Polaris\Fetching\Entity\FetchSlotRun;
use Polaris\Vehicle\Entity\Vehicle;

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
}
