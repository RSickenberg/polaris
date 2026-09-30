<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Lease\Domain;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Lease\Domain\Budget;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Shared\Domain\Distance;

#[CoversClass(Budget::class)]
final class BudgetTest extends TestCase
{
    public function testSpreadsTheRemainingDistanceOverTheTimeLeft(): void
    {
        // 60-month lease from 2026-01-01 to 2031-01-01 = 1,826 days, with the whole term left.
        // Day: 60,000,000 / 1,826 = 32,858.7 -> 32,859. Week: 7 x that = 230,010.96 -> 230,011.
        // Month: 1/60 of the term, so 60,000,000 / 60 = 1,000,000 exactly.
        $budget = Budget::spread(Distance::fromMetres(60_000_000), self::term(), 1_826 * 86_400);

        self::assertSame(32_859, $budget->perDay->metres());
        self::assertSame(230_011, $budget->perWeek->metres());
        self::assertSame(1_000_000, $budget->perMonth->metres());
    }

    public function testAMonthIsOneLeaseMonthNotACalendarMonth(): void
    {
        // Half the term left (913 days): 30,000,000 m over 30 lease months = 1,000,000 a month.
        $budget = Budget::spread(Distance::fromMetres(30_000_000), self::term(), 913 * 86_400);

        self::assertSame(1_000_000, $budget->perMonth->metres());
    }

    public function testNothingLeftGivesNoBudget(): void
    {
        self::assertEquals(Budget::none(), Budget::spread(Distance::fromMetres(0), self::term(), 100 * 86_400));
    }

    public function testNoTimeLeftGivesNoBudget(): void
    {
        self::assertEquals(Budget::none(), Budget::spread(Distance::fromMetres(1_000), self::term(), 0));
    }

    private static function term(): LeaseTerm
    {
        return new LeaseTerm(new CarbonImmutable('2026-01-01', 'UTC'), new CarbonImmutable('2031-01-01', 'UTC'), EndDateConvention::Exclusive);
    }
}
