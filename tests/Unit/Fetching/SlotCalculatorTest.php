<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Fetching;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Fetching\Domain\LocalTime;
use Polaris\Fetching\Domain\SlotCalculator;

#[CoversClass(SlotCalculator::class)]
#[CoversClass(LocalTime::class)]
final class SlotCalculatorTest extends TestCase
{
    /**
     * @param list<string> $slots
     * @param list<string> $expected UTC instants, oldest first
     */
    #[DataProvider('days')]
    public function testSlotsBecomeUtcInstantsOfTheLocalDays(string $now, string $timeZone, array $slots, array $expected): void
    {
        $instants = new SlotCalculator()->around(
            CarbonImmutable::parse($now, 'UTC'),
            $timeZone,
            array_map(LocalTime::fromString(...), $slots),
        );

        self::assertSame($expected, array_map(static fn (CarbonImmutable $instant): string => $instant->format('Y-m-d H:i e'), $instants));
    }

    /**
     * @return iterable<string, array{string, string, list<string>, list<string>}>
     */
    public static function days(): iterable
    {
        yield 'summer time in Zurich (UTC+2)' => [
            '2026-07-10 12:00',
            'Europe/Zurich',
            ['21:00'],
            ['2026-07-09 19:00 UTC', '2026-07-10 19:00 UTC'],
        ];

        yield 'winter time in Zurich (UTC+1)' => [
            '2026-12-10 12:00',
            'Europe/Zurich',
            ['21:00'],
            ['2026-12-09 20:00 UTC', '2026-12-10 20:00 UTC'],
        ];

        // Summer time starts on 2026-03-29: the 21:00 slot moves from 20:00 UTC to 19:00 UTC.
        yield 'the day summer time starts' => [
            '2026-03-29 12:00',
            'Europe/Zurich',
            ['21:00'],
            ['2026-03-28 20:00 UTC', '2026-03-29 19:00 UTC'],
        ];

        // Summer time ends on 2026-10-25: the 21:00 slot moves from 19:00 UTC to 20:00 UTC.
        yield 'the day summer time ends' => [
            '2026-10-25 12:00',
            'Europe/Zurich',
            ['21:00'],
            ['2026-10-24 19:00 UTC', '2026-10-25 20:00 UTC'],
        ];

        yield 'two slots a day, oldest first' => [
            '2026-10-26 12:00',
            'Europe/Zurich',
            ['21:00', '08:00'],
            ['2026-10-25 07:00 UTC', '2026-10-25 20:00 UTC', '2026-10-26 07:00 UTC', '2026-10-26 20:00 UTC'],
        ];

        yield 'the local day is not the UTC day' => [
            '2026-10-26 02:00',
            'America/Los_Angeles',
            ['21:00'],
            ['2026-10-25 04:00 UTC', '2026-10-26 04:00 UTC'],
        ];

        yield 'UTC' => [
            '2026-10-26 12:00',
            'UTC',
            ['21:00'],
            ['2026-10-25 21:00 UTC', '2026-10-26 21:00 UTC'],
        ];
    }

    public function testATimeInTheSkippedHourOfSpringMovesForward(): void
    {
        // 02:30 does not exist in Zurich on 2026-03-29 (02:00 jumps to 03:00): PHP reads it as 03:30 local, 01:30 UTC.
        $instants = new SlotCalculator()->around(
            CarbonImmutable::parse('2026-03-29 12:00', 'UTC'),
            'Europe/Zurich',
            [LocalTime::fromString('02:30')],
        );

        self::assertSame('2026-03-29 01:30', $instants[1]->format('Y-m-d H:i'));
    }

    public function testATimeThatHappensTwiceInAutumnIsTheFirstOccurrence(): void
    {
        // 02:30 happens at 00:30 UTC (summer time) and again at 01:30 UTC (winter time) on 2026-10-25.
        $instants = new SlotCalculator()->around(
            CarbonImmutable::parse('2026-10-25 12:00', 'UTC'),
            'Europe/Zurich',
            [LocalTime::fromString('02:30')],
        );

        self::assertSame('2026-10-25 00:30', $instants[1]->format('Y-m-d H:i'));
    }

    public function testTwoSlotsAtTheSameInstantAreOne(): void
    {
        $instants = new SlotCalculator()->around(
            CarbonImmutable::parse('2026-10-26 12:00', 'UTC'),
            'UTC',
            [LocalTime::fromString('21:00'), LocalTime::fromString('21:00')],
        );

        self::assertCount(2, $instants);
    }

    public function testAnUnknownTimeZoneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SlotCalculator()->around(CarbonImmutable::parse('2026-10-26 12:00', 'UTC'), 'Mars/Olympus', [LocalTime::default()]);
    }

    public function testTheDefaultSlotIsNineInTheEvening(): void
    {
        self::assertSame('21:00', (string) LocalTime::default());
    }

    #[DataProvider('invalidTimes')]
    public function testAMalformedLocalTimeIsRefused(string $time): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LocalTime::fromString($time);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTimes(): iterable
    {
        yield 'hour out of range' => ['24:00'];
        yield 'minute out of range' => ['12:60'];
        yield 'no leading zero' => ['9:00'];
        yield 'seconds' => ['09:00:00'];
        yield 'text' => ['evening'];
        yield 'empty' => [''];
    }
}
