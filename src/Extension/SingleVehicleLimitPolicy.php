<?php

declare(strict_types=1);

namespace Polaris\Extension;

use Polaris\Account\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * A self-hosted Polaris tracks a single vehicle.
 */
#[AsAlias(VehicleLimitPolicy::class)]
final readonly class SingleVehicleLimitPolicy implements VehicleLimitPolicy
{
    public function limit(User $owner): int
    {
        return 1;
    }
}
