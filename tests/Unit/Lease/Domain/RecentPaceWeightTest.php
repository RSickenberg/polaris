<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Lease\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Lease\Domain\Pace;
use Polaris\Lease\Domain\RecentPaceWeight;

#[CoversClass(RecentPaceWeight::class)]
final class RecentPaceWeightTest extends TestCase
{
    public function testFromBasisPoints(): void
    {
        self::assertSame(6_000, RecentPaceWeight::fromBasisPoints(6_000)->basisPoints());
    }

    /**
     * @return iterable<string, array{int, float}>
     */
    public static function blends(): iterable
    {
        // Recent 120,000 m/day, overall 90,000 m/day.
        yield 'overall only' => [0, 90_000.0];
        // 0.6 x 120,000 + 0.4 x 90,000 = 72,000 + 36,000.
        yield 'default 0.6' => [6_000, 108_000.0];
        // 0.25 x 120,000 + 0.75 x 90,000 = 30,000 + 67,500.
        yield 'quarter' => [2_500, 97_500.0];
        yield 'recent only' => [10_000, 120_000.0];
    }

    #[DataProvider('blends')]
    public function testBlend(int $basisPoints, float $metresPerDay): void
    {
        $blended = RecentPaceWeight::fromBasisPoints($basisPoints)
            ->blend(Pace::fromMetresPerDay(120_000.0), Pace::fromMetresPerDay(90_000.0));

        self::assertSame($metresPerDay, $blended->metresPerDay());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidBasisPoints(): iterable
    {
        yield 'negative' => [-1];
        yield 'above 1' => [RecentPaceWeight::MAX_BASIS_POINTS + 1];
    }

    #[DataProvider('invalidBasisPoints')]
    public function testOutOfRangeIsRejected(int $basisPoints): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RecentPaceWeight::fromBasisPoints($basisPoints);
    }
}
