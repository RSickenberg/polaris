<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Tesla\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Tesla\Domain\TeslaScopes;

#[CoversClass(TeslaScopes::class)]
final class TeslaScopesTest extends TestCase
{
    public function testTheRequestedScopesHaveTheCommandScopeAndNeverTheLocationOne(): void
    {
        self::assertStringContainsString('vehicle_cmds', TeslaScopes::REQUESTED);
        self::assertStringContainsString('vehicle_device_data', TeslaScopes::REQUESTED);
        self::assertStringNotContainsString('vehicle_location', TeslaScopes::REQUESTED);
    }

    public function testOnlyAWholeScopeAllowsWaking(): void
    {
        self::assertTrue(TeslaScopes::allowsWake('openid offline_access vehicle_device_data vehicle_cmds'));
        self::assertTrue(TeslaScopes::allowsWake('vehicle_cmds'));
        self::assertFalse(TeslaScopes::allowsWake('openid offline_access vehicle_device_data'));
        self::assertFalse(TeslaScopes::allowsWake('vehicle_cmds_extra'));
        self::assertFalse(TeslaScopes::allowsWake(''));
    }
}
