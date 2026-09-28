<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Shared\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\DistanceUnit;

#[CoversClass(Distance::class)]
#[CoversClass(DistanceUnit::class)]
final class DistanceTest extends TestCase
{
    public function testFromMetres(): void
    {
        self::assertSame(1234, Distance::fromMetres(1234)->metres());
        self::assertSame(0, Distance::fromMetres(0)->metres());
    }

    public function testFromKilometres(): void
    {
        self::assertSame(15_000_000, Distance::fromKilometres(15_000)->metres());
        self::assertSame(12_346, Distance::fromKilometres(12.3456)->metres());
    }

    public function testFromMilesUsesTheExactFactor(): void
    {
        self::assertSame(1609, Distance::fromMiles(1)->metres());
        self::assertSame(16_093_440, Distance::fromMiles(10_000)->metres());
        // A Tesla odometer reading, in miles with three decimals.
        self::assertSame(19_868_443, Distance::fromMiles(12_345.678)->metres());
    }

    public function testFromRoundsHalfUpToTheMetre(): void
    {
        self::assertSame(2, Distance::fromKilometres(0.0015)->metres());
        self::assertSame(1, Distance::fromKilometres(0.0014)->metres());
    }

    public function testInConvertsForDisplay(): void
    {
        $distance = Distance::fromMetres(16_093_440);

        self::assertSame(16_093.44, $distance->in(DistanceUnit::Kilometre));
        self::assertEqualsWithDelta(10_000.0, $distance->in(DistanceUnit::Mile), 1e-9);
    }

    public function testEquals(): void
    {
        self::assertTrue(Distance::fromKilometres(1)->equals(Distance::fromMetres(1000)));
        self::assertFalse(Distance::fromKilometres(1)->equals(Distance::fromMetres(1001)));
    }

    public function testNegativeMetresAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Distance::fromMetres(-1);
    }

    /**
     * @return iterable<string, array{float|int, DistanceUnit}>
     */
    public static function invalidValues(): iterable
    {
        yield 'negative kilometres' => [-0.5, DistanceUnit::Kilometre];
        yield 'negative miles' => [-1, DistanceUnit::Mile];
        yield 'huge negative value' => [-1e30, DistanceUnit::Kilometre];
        yield 'infinite' => [\INF, DistanceUnit::Kilometre];
        yield 'not a number' => [\NAN, DistanceUnit::Mile];
        yield 'too large for an integer' => [1e30, DistanceUnit::Kilometre];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreRejected(float|int $value, DistanceUnit $unit): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Distance::from($value, $unit);
    }
}
