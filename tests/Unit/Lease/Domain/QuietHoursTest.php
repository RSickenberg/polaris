<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Lease\Domain;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Lease\Domain\QuietHours;

#[CoversClass(QuietHours::class)]
final class QuietHoursTest extends TestCase
{
    public function testAPeriodCrossingMidnightCountsOnBothSides(): void
    {
        $quiet = QuietHours::between('22:00', '06:00', 'UTC');

        // 21:00 to 07:00 holds 22:00 to 06:00: 8 hours.
        self::assertSame(
            8 * 3600,
            $quiet->secondsBetween($this->at('2026-03-01 21:00', 'UTC'), $this->at('2026-03-02 07:00', 'UTC'))
        );
        // Only the evening part, then only the morning part.
        self::assertSame(
            2 * 3600,
            $quiet->secondsBetween($this->at('2026-03-01 20:00', 'UTC'), $this->at('2026-03-02 00:00', 'UTC'))
        );
        self::assertSame(
            6 * 3600,
            $quiet->secondsBetween($this->at('2026-03-02 00:00', 'UTC'), $this->at('2026-03-02 12:00', 'UTC'))
        );
    }

    public function testASameDayPeriodCountsOncePerDay(): void
    {
        $quiet = QuietHours::between('13:00', '15:00', 'UTC');

        self::assertSame(
            3 * 2 * 3600,
            $quiet->secondsBetween($this->at('2026-03-01 00:00', 'UTC'), $this->at('2026-03-04 00:00', 'UTC'))
        );
    }

    public function testAnInstantInsideThePeriodCountsFromItsOwnStart(): void
    {
        $quiet = QuietHours::between('22:00', '06:00', 'UTC');

        // 23:00 to 01:00 is entirely quiet.
        self::assertSame(
            2 * 3600,
            $quiet->secondsBetween($this->at('2026-03-01 23:00', 'UTC'), $this->at('2026-03-02 01:00', 'UTC'))
        );
        self::assertSame(0, $quiet->secondsBetween(100, 100));
        self::assertSame(0, $quiet->secondsBetween(200, 100));
    }

    public function testTheLocalTimesFollowTheOwnersZone(): void
    {
        $quiet = QuietHours::between('22:00', '06:00', 'Europe/Zurich');

        // Zurich is UTC+1 in January: 22:00 to 06:00 local is 21:00 to 05:00 UTC.
        self::assertSame(
            8 * 3600,
            $quiet->secondsBetween($this->at('2026-01-10 21:00', 'UTC'), $this->at('2026-01-11 05:00', 'UTC'))
        );
        self::assertSame(
            0,
            $quiet->secondsBetween($this->at('2026-01-10 05:00', 'UTC'), $this->at('2026-01-10 20:59', 'UTC'))
        );
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function daylightSavingNights(): iterable
    {
        // Clocks go forward on 2026-03-29 at 02:00 (23 h day): the night 22:00 to 06:00 is 7 hours long.
        yield 'spring forward' => ['2026-03-28 12:00', '2026-03-29 12:00', 7 * 3600];
        // Clocks go back on 2026-10-25 at 03:00 (25 h day): the night is 9 hours long.
        yield 'fall back' => ['2026-10-24 12:00', '2026-10-25 12:00', 9 * 3600];
        // An ordinary night, for comparison.
        yield 'ordinary' => ['2026-05-10 12:00', '2026-05-11 12:00', 8 * 3600];
    }

    #[DataProvider('daylightSavingNights')]
    public function testADaylightSavingNightHasItsRealLength(string $from, string $to, int $expected): void
    {
        $quiet = QuietHours::between('22:00', '06:00', 'Europe/Zurich');

        self::assertSame(
            $expected,
            $quiet->secondsBetween($this->at($from, 'Europe/Zurich'), $this->at($to, 'Europe/Zurich'))
        );
    }

    public function testTheTimesAreChecked(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        QuietHours::between('22:00', '22:00', 'UTC');
    }

    public function testATimeMustBeHoursAndMinutes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        QuietHours::between('24:00', '06:00', 'UTC');
    }

    public function testTheZoneMustBeAnIanaName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        QuietHours::between('22:00', '06:00', 'Mars/Base');
    }

    private function at(string $local, string $zone): int
    {
        return CarbonImmutable::parse($local, $zone)->getTimestamp();
    }
}
