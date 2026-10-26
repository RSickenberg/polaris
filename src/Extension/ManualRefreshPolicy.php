<?php

declare(strict_types=1);

namespace Polaris\Extension;

use Polaris\Vehicle\Entity\Vehicle;

/**
 * Decides whether a vehicle's manual refreshes are limited to one per hour.
 *
 * The self-hosted default follows POLARIS_MANUAL_REFRESH_LIMIT: the owner pays the Tesla bill and may lift
 * the limit. The hosted layer replaces it (service decoration or a new alias) and always limits.
 */
interface ManualRefreshPolicy
{
    public function isLimited(Vehicle $vehicle): bool;
}
