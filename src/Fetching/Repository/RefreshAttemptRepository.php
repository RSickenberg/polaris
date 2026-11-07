<?php

declare(strict_types=1);

namespace Polaris\Fetching\Repository;

use Carbon\CarbonImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Polaris\Fetching\Domain\RefreshOutcome;
use Polaris\Fetching\Entity\RefreshAttempt;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bridge\Doctrine\Types\UlidType;
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

    /**
     * The wakes of the vehicle since $since, most recent first.
     *
     * @return list<CarbonImmutable>
     */
    public function wakesSince(Vehicle $vehicle, CarbonImmutable $since): array
    {
        /** @var list<array{wokeAt: CarbonImmutable}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.wokeAt')
            ->where('a.vehicle = :vehicle')
            ->andWhere('a.wokeAt > :since')
            ->orderBy('a.wokeAt', \SortDirection::Descending)
            ->setParameter('vehicle', $vehicle->getId(), UlidType::NAME)
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): CarbonImmutable => $row['wokeAt'], $rows);
    }

    /**
     * Whether wake_up was sent for the vehicle recently enough that its poll may still be running.
     */
    public function hasPendingWake(Vehicle $vehicle, CarbonImmutable $since): bool
    {
        return [] !== $this->createQueryBuilder('a')
            ->select('a.id')
            ->where('a.vehicle = :vehicle')
            ->andWhere('a.outcome = :outcome')
            ->andWhere('a.wokeAt > :since')
            ->setMaxResults(1)
            ->setParameter('vehicle', $vehicle->getId(), UlidType::NAME)
            ->setParameter('outcome', RefreshOutcome::Waking)
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getResult();
    }

    /**
     * Deletes the attempts of an owner's vehicles that were requested before $before and whose wake, if any, was sent
     * before it too (the wake cap still counts a younger one).
     *
     * @return int how many rows were deleted
     */
    public function deleteOfOwnerBefore(Ulid $ownerId, CarbonImmutable $before): int
    {
        $vehicles = $this->getEntityManager()->createQueryBuilder()
            ->select('v.id')
            ->from(Vehicle::class, 'v')
            ->where('v.owner = :owner')
            ->getDQL();

        return (int) $this->createQueryBuilder('a')
            ->delete()
            ->where('a.vehicle IN (' . $vehicles . ')')
            ->andWhere('a.requestedAt < :before')
            ->andWhere('a.wokeAt IS NULL OR a.wokeAt < :before')
            ->setParameter('owner', $ownerId, 'ulid')
            ->setParameter('before', $before, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->execute();
    }
}
