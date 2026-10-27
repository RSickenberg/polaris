<?php

declare(strict_types=1);

namespace Polaris\Reading\Service;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Reading\Entity\VehicleDataSnapshot;
use Polaris\Reading\Repository\VehicleDataSnapshotRepository;
use Polaris\Vehicle\Entity\Vehicle;

/**
 * Keeps the answer of a live `vehicle_data` call. An answer the car reported at an instant already kept (it
 * answered from the same state twice) is not stored again.
 */
final readonly class VehicleDataRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private VehicleDataSnapshotRepository $snapshots,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload the answer without its location, see {@see \Polaris\Tesla\Domain\VehicleDataRedactor}
     *
     * @return bool whether a row was added
     *
     * @throws ORMException
     */
    public function record(Vehicle $vehicle, CarbonImmutable $capturedAt, CarbonImmutable $receivedAt, array $payload): bool
    {
        if ([] === $payload || null !== $this->snapshots->findAt($vehicle, $capturedAt)) {
            return false;
        }

        $this->entityManager->persist(new VehicleDataSnapshot($vehicle, $capturedAt, $receivedAt, $payload));
        $this->entityManager->flush();

        return true;
    }
}
