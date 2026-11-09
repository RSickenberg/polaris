<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Polaris\Account\Entity\User;
use Polaris\Extension\VehicleLimitPolicy;
use Polaris\Vehicle\Entity\Vehicle;
use Polaris\Vehicle\Service\Exception\VehicleLimitReached;

/**
 * Applies the {@see VehicleLimitPolicy} to the places that create vehicles (the import from Tesla and
 * `polaris:vehicle:add`). Check before persisting: vehicles not flushed yet are not counted.
 */
final readonly class VehicleQuota
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private VehicleLimitPolicy $policy,
    ) {
    }

    /**
     * @return int|null how many more vehicles the user may add, null when there is no limit
     */
    public function remaining(User $owner): ?int
    {
        $limit = $this->policy->limit($owner);

        if (null === $limit) {
            return null;
        }

        return max(0, $limit - $this->entityManager->getRepository(Vehicle::class)->count(['owner' => $owner]));
    }

    /**
     * @throws VehicleLimitReached when adding $count vehicles would go past the limit
     */
    public function assertCanAdd(User $owner, int $count = 1): void
    {
        $remaining = $this->remaining($owner);

        if (null !== $remaining && $count > $remaining) {
            throw new VehicleLimitReached((int) $this->policy->limit($owner));
        }
    }
}
