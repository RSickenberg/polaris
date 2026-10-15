<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Tesla\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Tesla\Domain\FleetRegion;

#[CoversClass(FleetRegion::class)]
final class FleetRegionTest extends TestCase
{
    public function testEachRegionHasItsBaseUrl(): void
    {
        self::assertSame('https://fleet-api.prd.na.vn.cloud.tesla.com', FleetRegion::NorthAmericaAsiaPacific->baseUrl());
        self::assertSame('https://fleet-api.prd.eu.vn.cloud.tesla.com', FleetRegion::EuropeMiddleEastAfrica->baseUrl());
        self::assertSame('https://fleet-api.prd.cn.vn.cloud.tesla.cn', FleetRegion::China->baseUrl());
    }

    public function testChinaIsTheOnlyUnsupportedRegion(): void
    {
        self::assertSame(
            [FleetRegion::NorthAmericaAsiaPacific, FleetRegion::EuropeMiddleEastAfrica],
            FleetRegion::supported(),
        );
        self::assertFalse(FleetRegion::China->isSupported());
    }

    public function testTheGlobalTokenHostServesTheSupportedRegions(): void
    {
        foreach (FleetRegion::supported() as $region) {
            self::assertSame('https://fleet-auth.prd.vn.cloud.tesla.com/oauth2/v3/token', $region->tokenUrl());
        }
    }

    public function testChinaHasNoKnownTokenHost(): void
    {
        $this->expectException(\LogicException::class);

        FleetRegion::China->tokenUrl();
    }
}
