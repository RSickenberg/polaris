<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Dashboard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Dashboard\Domain\AllowanceProgress;
use Polaris\Shared\Domain\Distance;

#[CoversClass(AllowanceProgress::class)]
final class AllowanceProgressTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, int, int, int, int, bool}>
     */
    public static function shares(): iterable
    {
        // driven km, allowed to date km, total km, used %, earned %, bar %, over
        yield 'nothing driven' => [0, 0, 45_000, 0, 0, 0, false];
        yield 'half way' => [22_500, 15_000, 45_000, 50, 33, 50, false];
        yield 'rounds half up' => [1, 1, 200, 1, 1, 1, false]; // 0.5 %
        yield 'rounds down below half' => [1, 1, 400, 0, 0, 0, false]; // 0.25 %
        yield 'exactly the allowance' => [45_000, 45_000, 45_000, 100, 100, 100, false];
        yield 'over the allowance' => [50_400, 45_000, 45_000, 112, 100, 100, true];
        yield 'no allowance' => [10, 0, 0, 0, 0, 0, false];
    }

    #[DataProvider('shares')]
    public function testSharesOfTheAllowance(int $driven, int $allowed, int $total, int $used, int $earned, int $bar, bool $over): void
    {
        $progress = AllowanceProgress::of(
            Distance::fromKilometres($driven),
            Distance::fromKilometres($allowed),
            Distance::fromKilometres($total),
        );

        self::assertSame($used, $progress->usedPercent);
        self::assertSame($earned, $progress->earnedPercent);
        self::assertSame($bar, $progress->barPercent());
        self::assertSame($over, $progress->isOver());
    }

    public function testTheTickNeverGoesBeyondTheEndOfTheBar(): void
    {
        $progress = AllowanceProgress::of(Distance::fromKilometres(10), Distance::fromKilometres(200), Distance::fromKilometres(100));

        self::assertSame(100, $progress->earnedPercent);
    }
}
