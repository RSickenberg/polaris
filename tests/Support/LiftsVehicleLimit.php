<?php

declare(strict_types=1);

namespace Polaris\Tests\Support;

use Polaris\Extension\VehicleLimitPolicy;

/**
 * For a test that needs several vehicles for one user: the self-hosted default allows one. Call it before the first
 * service that reads the policy is created.
 */
trait LiftsVehicleLimit
{
    private function liftVehicleLimit(): void
    {
        $policy = $this->createStub(VehicleLimitPolicy::class);
        $policy->method('limit')->willReturn(null);

        self::getContainer()->set(VehicleLimitPolicy::class, $policy);
    }
}
