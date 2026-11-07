<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Shared\Domain;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Shared\Domain\OwnerMidnight;

#[CoversClass(OwnerMidnight::class)]
final class OwnerMidnightTest extends TestCase
{
    #[DataProvider('latest')]
    public function testTheLatestMidnightAtOrBeforeNow(string $now, string $timeZone, string $expectedAt, string $expectedDay): void
    {
        $midnight = OwnerMidnight::latestAt(CarbonImmutable::parse($now, 'UTC'), $timeZone);

        self::assertSame($expectedAt, $midnight->at->format('Y-m-d H:i:s e'));
        self::assertSame($expectedDay, $midnight->localDay->format('Y-m-d H:i:s e'));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function latest(): iterable
    {
        yield 'summer in Zurich' => ['2026-07-10 12:00', 'Europe/Zurich', '2026-07-09 22:00:00 UTC', '2026-07-10 00:00:00 UTC'];
        yield 'winter in Zurich' => ['2026-12-10 12:00', 'Europe/Zurich', '2026-12-09 23:00:00 UTC', '2026-12-10 00:00:00 UTC'];
        yield 'exactly at midnight' => ['2026-07-09 22:00', 'Europe/Zurich', '2026-07-09 22:00:00 UTC', '2026-07-10 00:00:00 UTC'];
        yield 'one second before midnight' => ['2026-07-09 21:59:59', 'Europe/Zurich', '2026-07-08 22:00:00 UTC', '2026-07-09 00:00:00 UTC'];
        yield 'autumn change day, 25 hours (Zurich 2026-10-25)' => ['2026-10-25 12:00', 'Europe/Zurich', '2026-10-24 22:00:00 UTC', '2026-10-25 00:00:00 UTC'];
        yield 'the day after the 25 hour day' => ['2026-10-26 00:30', 'Europe/Zurich', '2026-10-25 23:00:00 UTC', '2026-10-26 00:00:00 UTC'];
        yield 'spring change day, 23 hours (Zurich 2027-03-28)' => ['2027-03-28 12:00', 'Europe/Zurich', '2027-03-27 23:00:00 UTC', '2027-03-28 00:00:00 UTC'];
        yield 'the day after the 23 hour day' => ['2027-03-29 00:30', 'Europe/Zurich', '2027-03-28 22:00:00 UTC', '2027-03-29 00:00:00 UTC'];
        yield 'Americas (Los Angeles, UTC-7 in summer)' => ['2026-07-10 05:00', 'America/Los_Angeles', '2026-07-09 07:00:00 UTC', '2026-07-09 00:00:00 UTC'];
        yield 'India (+05:30)' => ['2026-07-10 12:00', 'Asia/Kolkata', '2026-07-09 18:30:00 UTC', '2026-07-10 00:00:00 UTC'];
        yield 'Nepal (+05:45)' => ['2026-07-10 12:00', 'Asia/Kathmandu', '2026-07-09 18:15:00 UTC', '2026-07-10 00:00:00 UTC'];
        yield 'UTC' => ['2026-07-10 12:00', 'UTC', '2026-07-10 00:00:00 UTC', '2026-07-10 00:00:00 UTC'];
        // Sao Paulo had no 2018-11-04 00:00: the clocks went from 23:59:59 to 01:00 (the zone dropped DST in 2019).
        yield 'midnight that does not exist (Sao Paulo 2018-11-04)' => ['2018-11-04 12:00', 'America/Sao_Paulo', '2018-11-04 03:00:00 UTC', '2018-11-04 00:00:00 UTC'];
    }

    public function testAJobIsDueFiveMinutesAfterMidnight(): void
    {
        $zone = 'Europe/Zurich';

        // Midnight of 2026-07-10 is 2026-07-09 22:00 UTC.
        self::assertSame('2026-07-08 22:00:00', OwnerMidnight::latestDue(CarbonImmutable::parse('2026-07-09 22:04:59', 'UTC'), $zone)->at->format('Y-m-d H:i:s'));
        self::assertSame('2026-07-09 22:00:00', OwnerMidnight::latestDue(CarbonImmutable::parse('2026-07-09 22:05:00', 'UTC'), $zone)->at->format('Y-m-d H:i:s'));
    }

    public function testAZoneChangeMovesTheLabelWithoutRepeatingOrSkippingAnInstant(): void
    {
        $now = CarbonImmutable::parse('2026-11-07 10:00', 'UTC');

        self::assertSame('2026-11-07', OwnerMidnight::latestAt($now, 'Europe/Zurich')->localDay->format('Y-m-d'));
        self::assertSame('2026-11-07', OwnerMidnight::latestAt($now, 'America/Los_Angeles')->localDay->format('Y-m-d'));
        self::assertSame('2026-11-07', OwnerMidnight::latestAt($now, 'Pacific/Auckland')->localDay->format('Y-m-d'));
        self::assertSame('2026-11-06', OwnerMidnight::latestAt($now, 'Pacific/Pago_Pago')->localDay->format('Y-m-d'));
    }

    public function testTheMidnightThatOpensALocalDay(): void
    {
        $midnight = OwnerMidnight::opening(CarbonImmutable::parse('2026-10-25', 'UTC'), 'Europe/Zurich');

        self::assertSame('2026-10-24 22:00:00', $midnight->at->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-25', $midnight->localDay->format('Y-m-d'));
    }

    public function testAnUnknownTimeZoneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OwnerMidnight::latestAt(CarbonImmutable::parse('2026-07-10', 'UTC'), 'Mars/Olympus');
    }
}
