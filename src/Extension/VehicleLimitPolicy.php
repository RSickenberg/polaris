<?php

declare(strict_types=1);

namespace Polaris\Extension;

use Polaris\Account\Entity\User;

/**
 * Says how many vehicles a user may have.
 *
 * The self-hosted default allows one. The hosted layer replaces it (service decoration or a new alias) to apply its
 * own policy: plans, quotas. Vehicles a user already has are never removed when the limit goes down, only adding
 * another is refused.
 */
interface VehicleLimitPolicy
{
    /**
     * @return int|null the number of vehicles the user may have, null when there is no limit
     */
    public function limit(User $owner): ?int;
}
