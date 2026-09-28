<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Lease\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Lease\Domain\Tolerance;

#[CoversClass(Tolerance::class)]
final class ToleranceTest extends TestCase
{
    public function testFromBasisPoints(): void
    {
        $tolerance = Tolerance::fromBasisPoints(250);

        self::assertSame(250, $tolerance->basisPoints());
        self::assertSame(2.5, $tolerance->percent());
    }

    /**
     * @return iterable<string, array{float|int, int}>
     */
    public static function percentages(): iterable
    {
        yield 'zero' => [0, 0];
        yield 'whole' => [10, 1000];
        yield 'half' => [2.5, 250];
        yield 'rounded half up' => [2.505, 251];
        yield 'maximum' => [50, 5000];
    }

    #[DataProvider('percentages')]
    public function testFromPercent(float|int $percent, int $basisPoints): void
    {
        self::assertSame($basisPoints, Tolerance::fromPercent($percent)->basisPoints());
    }

    /**
     * @return iterable<string, array{float|int}>
     */
    public static function invalidPercentages(): iterable
    {
        yield 'negative' => [-1];
        yield 'above 50%' => [50.01];
        yield 'huge' => [1e30];
        yield 'infinite' => [\INF];
        yield 'not a number' => [\NAN];
    }

    #[DataProvider('invalidPercentages')]
    public function testInvalidPercentIsRejected(float|int $percent): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Tolerance::fromPercent($percent);
    }

    public function testBasisPointsOutOfRangeAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Tolerance::fromBasisPoints(Tolerance::MAX_BASIS_POINTS + 1);
    }

    public function testNegativeBasisPointsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Tolerance::fromBasisPoints(-1);
    }
}
