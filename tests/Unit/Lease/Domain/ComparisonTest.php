<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Lease\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Lease\Domain\Comparison;
use Polaris\Shared\Domain\Distance;

#[CoversClass(Comparison::class)]
final class ComparisonTest extends TestCase
{
    public function testMoreThanExpectedIsAPositiveDeltaAndShare(): void
    {
        $comparison = Comparison::of(Distance::fromMetres(55_000), Distance::fromMetres(50_000));

        self::assertSame(5_000, $comparison->delta->metres());
        self::assertFalse($comparison->delta->isNegative());
        self::assertSame(0.1, $comparison->ratio);
    }

    public function testLessThanExpectedIsANegativeDeltaAndShare(): void
    {
        $comparison = Comparison::of(Distance::fromMetres(45_000), Distance::fromMetres(50_000));

        self::assertSame(-5_000, $comparison->delta->metres());
        self::assertTrue($comparison->delta->isNegative());
        self::assertSame(-0.1, $comparison->ratio);
    }

    public function testNothingExpectedGivesTheDeltaWithoutAShare(): void
    {
        $comparison = Comparison::of(Distance::fromMetres(3_000), Distance::fromMetres(0));

        self::assertSame(3_000, $comparison->delta->metres());
        self::assertNull($comparison->ratio);
    }
}
