<?php

declare(strict_types=1);

namespace Polaris\Tests\Support;

use Polaris\Extension\ManualRefreshPolicy;
use Polaris\Vehicle\Entity\Vehicle;

/**
 * A manual refresh policy a test switches on and off after the services were built.
 */
final class ToggledRefreshPolicy implements ManualRefreshPolicy
{
    public bool $limited = false;

    public function isLimited(Vehicle $vehicle): bool
    {
        return $this->limited;
    }
}
