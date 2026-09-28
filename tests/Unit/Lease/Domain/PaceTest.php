<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Lease\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Lease\Domain\Pace;

#[CoversClass(Pace::class)]
final class PaceTest extends TestCase
{
    public function testFromMetresPerDay(): void
    {
        self::assertSame(100_000.0, Pace::fromMetresPerDay(100_000.0)->metresPerDay());
        self::assertSame(0.0, Pace::fromMetresPerDay(0.0)->metresPerDay());
    }

    public function testOverConvertsSecondsToDays(): void
    {
        // 9,000 km in 100 days = 90,000 m/day.
        self::assertSame(90_000.0, Pace::over(9_000_000, 100 * 86_400)->metresPerDay());
        // 1 m in 2 days = 0.5 m/day.
        self::assertSame(0.5, Pace::over(1, 2 * 86_400)->metresPerDay());
    }

    public function testMetresIn(): void
    {
        // 108,000 m/day for 265 days = 28,620,000 m.
        self::assertSame(28_620_000.0, Pace::fromMetresPerDay(108_000.0)->metresIn(265 * 86_400));
        // Unrounded: 0.5 m/day for 363 days = 181.5 m.
        self::assertSame(181.5, Pace::fromMetresPerDay(0.5)->metresIn(363 * 86_400));
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidPaces(): iterable
    {
        yield 'negative' => [-0.1];
        yield 'infinite' => [\INF];
        yield 'not a number' => [\NAN];
    }

    #[DataProvider('invalidPaces')]
    public function testInvalidPaceIsRejected(float $metresPerDay): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Pace::fromMetresPerDay($metresPerDay);
    }

    public function testNegativeDistanceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Pace::over(-1, 86_400);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidDurations(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('invalidDurations')]
    public function testNonPositiveDurationIsRejected(int $seconds): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Pace::over(1_000, $seconds);
    }
}
