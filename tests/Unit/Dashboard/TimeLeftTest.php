<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Dashboard;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Dashboard\Domain\TimeLeft;

#[CoversClass(TimeLeft::class)]
final class TimeLeftTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int, int}>
     */
    public static function spans(): iterable
    {
        yield 'whole months' => ['2026-07-02 12:00:00', '2027-01-02 12:00:00', 6, 0];
        // 2 July 12:00 to 1 January 00:00: 5 months to 2 December 12:00, then 29.5 days, a started day counts.
        yield 'months and days' => ['2026-07-02 12:00:00', '2027-01-01 00:00:00', 5, 30];
        yield 'last month, days rounded up' => ['2026-12-20 18:00:00', '2027-01-01 00:00:00', 0, 12];
        yield 'less than a day' => ['2026-12-31 20:00:00', '2027-01-01 00:00:00', 0, 1];
        yield 'at the end' => ['2027-01-01 00:00:00', '2027-01-01 00:00:00', 0, 0];
        yield 'after the end' => ['2027-02-01 00:00:00', '2027-01-01 00:00:00', 0, 0];
    }

    #[DataProvider('spans')]
    public function testItCountsWholeMonthsThenDaysRoundedUp(string $now, string $end, int $months, int $days): void
    {
        $left = TimeLeft::between(CarbonImmutable::parse($now, 'UTC'), CarbonImmutable::parse($end, 'UTC'));

        self::assertSame(['months' => $months, 'days' => $days], ['months' => $left->months, 'days' => $left->days]);
    }
}
