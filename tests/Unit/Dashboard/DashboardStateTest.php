<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Dashboard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Dashboard\Service\Enum\DashboardState;

#[CoversClass(DashboardState::class)]
final class DashboardStateTest extends TestCase
{
    public function testOnlyARunningLeaseCanBeRefreshed(): void
    {
        foreach (DashboardState::cases() as $state) {
            self::assertSame(DashboardState::Active === $state, $state->canRefresh(), $state->name);
        }
    }

    public function testAnEndedOrReturnedLeaseHasNoForecast(): void
    {
        $ended = [DashboardState::Ended, DashboardState::Returned];

        foreach (DashboardState::cases() as $state) {
            self::assertSame(\in_array($state, $ended, true), $state->hasEnded(), $state->name);
        }
    }
}
