<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Lease\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Lease\Domain\ExcessCost;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\Money;

#[CoversClass(ExcessCost::class)]
final class ExcessCostTest extends TestCase
{
    public function testPricesTheExcessPerKilometre(): void
    {
        // 1,120 km at 0.45 CHF = 504.00 CHF = 50,400 centimes.
        $cost = ExcessCost::of(Distance::fromMetres(1_120_000), Money::fromDecimal('0.45', Currency::CHF));

        self::assertSame(50_400, $cost->minorAmount());
        self::assertSame(Currency::CHF, $cost->currency());
    }

    public function testRoundsHalfUpOnceToTheMinorUnit(): void
    {
        $price = Money::ofMinor(45, Currency::CHF);

        self::assertSame(0, ExcessCost::of(Distance::fromMetres(11), $price)->minorAmount()); // 0.495
        self::assertSame(1, ExcessCost::of(Distance::fromMetres(12), $price)->minorAmount()); // 0.54
        self::assertSame(5, ExcessCost::of(Distance::fromMetres(100), $price)->minorAmount()); // 4.5
    }

    public function testNoExcessCostsNothing(): void
    {
        self::assertSame(0, ExcessCost::of(Distance::fromMetres(0), Money::ofMinor(45, Currency::CHF))->minorAmount());
    }

    public function testRejectsANegativePrice(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ExcessCost::of(Distance::fromMetres(1_000), Money::ofMinor(-1, Currency::CHF));
    }
}
