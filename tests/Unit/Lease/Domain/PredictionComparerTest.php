<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Lease\Domain;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Lease\Domain\Comparison;
use Polaris\Lease\Domain\ComparisonWindow;
use Polaris\Lease\Domain\OdometerTimeline;
use Polaris\Lease\Domain\Pace;
use Polaris\Lease\Domain\PredictionComparer;
use Polaris\Lease\Domain\PredictionComparison;
use Polaris\Lease\Domain\Projection;
use Polaris\Lease\Domain\SnapshotFigures;
use Polaris\Lease\Domain\WindowComparison;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\DistanceDelta;
use Polaris\Shared\Domain\OdometerSample;
use Polaris\Shared\Domain\OwnerMidnight;

/**
 * The car drives 100,000 m a day, evenly, from the lease start (2026-01-01 00:00 UTC, odometer 0).
 */
#[CoversClass(PredictionComparer::class)]
#[CoversClass(Comparison::class)]
#[CoversClass(ComparisonWindow::class)]
#[CoversClass(WindowComparison::class)]
final class PredictionComparerTest extends TestCase
{
    private const int METRES_PER_DAY = 100_000;

    public function testTheThreeComparisonsOfTheLastDays(): void
    {
        // 2026-07-10 12:00 UTC is 190.5 days into the lease: 19,050,000 m.
        $latest = '2026-07-10 12:00:00';
        $comparison = $this->compare($latest, 36_000_000, [
            // Today started at 00:00, 12 hours ago: 50,000 m driven.
            '2026-07-10' => self::snapshot(36_500_000, 90_000, 80_000.0),
            // 7 days before: 7.5 days driven, 750,000 m; budget of 100,000 m a day expects exactly that, and no pace
            // was stored.
            '2026-07-03' => self::snapshot(36_000_000, 100_000, null),
            // 14 days before: no snapshot.
            // 30 days before: 30.5 days driven, 3,050,000 m; a budget of zero expects nothing.
            '2026-06-10' => self::snapshot(35_000_000, 0, 0.0),
        ]);

        // Budget: 90,000 m a day over 12 hours expects 45,000 m; 50,000 m were driven.
        self::assertSame([50_000, 45_000, 5_000], $this->figures($comparison->budget[0]));
        self::assertEqualsWithDelta(5_000 / 45_000, $comparison->budget[0]->comparison?->ratio, 1e-12);
        self::assertSame([750_000, 750_000, 0], $this->figures($comparison->budget[2]));
        self::assertSame(0.0, $comparison->budget[2]->comparison?->ratio);
        self::assertNull($comparison->budget[3]->comparison, 'No snapshot 14 days before.');
        self::assertSame([3_050_000, 0, 3_050_000], $this->figures($comparison->budget[4]));
        self::assertNull($comparison->budget[4]->comparison?->ratio, 'Nothing expected: the distance only.');

        // Pace: 80,000 m a day over 12 hours expects 40,000 m.
        self::assertSame([50_000, 40_000, 10_000], $this->figures($comparison->pace[0]));
        self::assertEqualsWithDelta(0.25, $comparison->pace[0]->comparison?->ratio, 1e-12);
        self::assertNull($comparison->pace[2]->comparison, 'The snapshot of 7 days before has no pace.');
        self::assertNull($comparison->pace[3]->comparison);
        self::assertSame([3_050_000, 0, 3_050_000], $this->figures($comparison->pace[4]));

        // Projected end: 36,000,000 m now against 36,500,000 m projected this morning.
        self::assertSame([36_000_000, 36_500_000, -500_000], $this->figures($comparison->projectedEnd[0]));
        self::assertEqualsWithDelta(-500_000 / 36_500_000, $comparison->projectedEnd[0]->comparison?->ratio, 1e-12);
        self::assertSame([36_000_000, 36_000_000, 0], $this->figures($comparison->projectedEnd[2]));
        self::assertNull($comparison->projectedEnd[3]->comparison);
    }

    public function testTodayCanBeMeasuredAgainstTheWholeDayAndTheOtherWindowsStayTheSame(): void
    {
        $latest = '2026-07-10 12:00:00';
        $snapshots = [
            '2026-07-10' => self::snapshot(36_500_000, 90_000, 80_000.0),
            '2026-07-03' => self::snapshot(36_000_000, 100_000, null),
        ];

        $prorated = $this->compareIn('UTC', $latest, $snapshots, 36_000_000);
        $wholeDay = $this->compareIn('UTC', $latest, $snapshots, 36_000_000, null, todayWholeDay: true);

        // 50,000 m driven in 12 hours. Prorated: 45,000 m of the 90,000 m budget are expected by now. Whole day: all
        // 90,000 m, so 40,000 m are still allowed.
        self::assertSame([50_000, 45_000, 5_000], $this->figures($prorated->budget[0]));
        self::assertSame([50_000, 90_000, -40_000], $this->figures($wholeDay->budget[0]));
        // The pace: 80,000 m a day.
        self::assertSame([50_000, 80_000, -30_000], $this->figures($wholeDay->pace[0]));
        self::assertEqualsWithDelta(-0.375, $wholeDay->pace[0]->comparison?->ratio, 1e-12);
        // The 7 day window and the projected end do not move.
        self::assertSame($this->figures($prorated->budget[2]), $this->figures($wholeDay->budget[2]));
        self::assertSame($this->figures($prorated->projectedEnd[0]), $this->figures($wholeDay->projectedEnd[0]));
    }

    public function testTheWholeDayIsTheLengthOfTheLocalDayOnADaylightSavingChange(): void
    {
        // Zurich leaves summer time on 2026-10-25: 25 hours, from 22:00 UTC the evening before. Five hours in, a
        // budget of 100,000 m a day expects the whole day: 25 hours are 100,000 m x 25 / 24.
        $snapshots = ['2026-10-25' => self::snapshot(1, self::METRES_PER_DAY, null)];

        $comparison = $this->compareIn('Europe/Zurich', '2026-10-25 03:00:00', $snapshots, 1, null, true);

        self::assertSame(
            (int) round(25 / 24 * self::METRES_PER_DAY),
            $this->figures($comparison->budget[0])[1],
        );
    }

    public function testTheWindowsAreInTheOrderOfTheEnum(): void
    {
        $latest = '2026-07-10 12:00:00';

        $comparison = $this->compare($latest, 36_000_000, []);

        foreach ([$comparison->budget, $comparison->pace, $comparison->projectedEnd] as $rows) {
            self::assertSame(
                [
                    ComparisonWindow::Today,
                    ComparisonWindow::Yesterday,
                    ComparisonWindow::LastWeek,
                    ComparisonWindow::LastTwoWeeks,
                    ComparisonWindow::LastMonth,
                ],
                array_map(static fn (WindowComparison $row): ComparisonWindow => $row->window, $rows),
            );
            self::assertSame(
                [null, null, null, null, null],
                array_map(static fn (WindowComparison $row): ?Comparison => $row->comparison, $rows),
            );
        }
    }

    public function testAWindowThatStartsBeforeTheLeaseHasNoResult(): void
    {
        // 5 days into the lease: the 7 day window starts before it, even with a snapshot for that day.
        $latest = '2026-01-06 12:00:00';
        $snapshots = ['2025-12-30' => self::snapshot(1, 100_000, 100_000.0)];

        $comparison = $this->compare($latest, 1, $snapshots);

        self::assertNull($comparison->budget[2]->comparison);
        self::assertNull($comparison->pace[2]->comparison);
    }

    public function testTodayHasNothingToMeasureBeforeTheFirstReadingOfTheOwnersDay(): void
    {
        // The latest reading is from 21:00 on 9 July; it is 02:00 on the 10th and nothing was read since midnight.
        $comparison = $this->compare(
            '2026-07-09 21:00:00',
            1,
            [
                '2026-07-10' => self::snapshot(1, 100_000, 100_000.0),
                '2026-07-03' => self::snapshot(1, 100_000, null),
            ],
            now: '2026-07-10 02:00:00',
        );

        foreach ([$comparison->budget, $comparison->pace, $comparison->projectedEnd] as $rows) {
            self::assertTrue($rows[0]->awaitingReading);
            self::assertNull($rows[0]->comparison, 'Yesterday\'s figures are not today\'s.');
            self::assertFalse($rows[1]->awaitingReading);
        }

        // The other windows start from the current day: 7 days before the 10th is the 3rd, and they end at the reading.
        // From 3 July 00:00 to 9 July 21:00 is 6.875 days: 687,500 m, as expected by a budget of 100,000 m a day.
        self::assertSame([687_500, 687_500, 0], $this->figures($comparison->budget[2]));
    }

    public function testYesterdayIsTheWholePreviousDayAgainstItsOwnSnapshot(): void
    {
        $comparison = $this->compare('2026-07-10 12:00:00', 36_500_000, [
            '2026-07-10' => self::snapshot(36_500_000, 100_000, 100_000.0),
            '2026-07-09' => self::snapshot(36_200_000, 90_000, 80_000.0),
        ]);

        // 100,000 m driven on the 9th, whatever happened since: a budget of 90,000 m and a pace of 80,000 m a day.
        self::assertSame([100_000, 90_000, 10_000], $this->figures($comparison->budget[1]));
        self::assertSame([100_000, 80_000, 20_000], $this->figures($comparison->pace[1]));
        self::assertEqualsWithDelta(10_000 / 90_000, $comparison->budget[1]->comparison?->ratio, 1e-12);
        // The estimate moved from 36,200,000 m (start of the 9th) to 36,500,000 m (start of the 10th): during the day.
        self::assertSame([36_500_000, 36_200_000, 300_000], $this->figures($comparison->projectedEnd[1]));
    }

    public function testYesterdayStopsAtTheLatestReadingWhenItCameBeforeMidnight(): void
    {
        // The reading of 21:00 on the 9th is the last one: yesterday covers 21 hours, and today waits for a reading.
        $comparison = $this->compare(
            '2026-07-09 21:00:00',
            1,
            ['2026-07-09' => self::snapshot(1, 100_000, null), '2026-07-10' => self::snapshot(1, 1, null)],
            now: '2026-07-10 02:00:00',
        );

        // 21 hours of 100,000 m a day: 87,500 m driven, 87,500 m expected.
        self::assertSame([87_500, 87_500, 0], $this->figures($comparison->budget[1]));
        self::assertTrue($comparison->budget[0]->awaitingReading);
    }

    public function testAReadingJustAfterMidnightShowsTheDistancesButNotThePercentage(): void
    {
        // 30 minutes after midnight: 5,000 m driven in the window, a budget of 100,000 m a day expects 2,083 m.
        $comparison = $this->compare(
            '2026-07-10 00:30:00',
            36_000_000,
            ['2026-07-10' => self::snapshot(36_000_000, 100_000, 100_000.0)],
        );

        self::assertSame([2_083, 2_083, 0], $this->figures($comparison->budget[0]));
        self::assertNull($comparison->budget[0]->comparison?->ratio);
        self::assertNull($comparison->pace[0]->comparison?->ratio);
        self::assertFalse($comparison->budget[0]->awaitingReading);
        // The projected end does not depend on the length of the window: its share stays.
        self::assertNotNull($comparison->projectedEnd[0]->comparison?->ratio);
    }

    public function testThePercentageComesBackOnceSixHoursOfTheWindowHavePassed(): void
    {
        $snapshots = ['2026-07-10' => self::snapshot(1, 100_000, 100_000.0)];

        $before = $this->compare('2026-07-10 05:59:59', 1, $snapshots);
        $exactly = $this->compare('2026-07-10 06:00:00', 1, $snapshots);

        self::assertNull($before->budget[0]->comparison?->ratio);
        self::assertSame(0.0, $exactly->budget[0]->comparison?->ratio);
        self::assertSame(0.0, $exactly->pace[0]->comparison?->ratio);
    }

    /**
     * The windows count real elapsed time from the owner's midnight, so a day of 23 or 25 hours changes nothing but
     * its length: 6 hours after midnight is 6 hours, whatever the clock on the wall says.
     */
    #[DataProvider('daylightSavingChanges')]
    public function testTheShortWindowRuleCountsElapsedHoursAcrossDaylightSavingChanges(
        string $midnightUtc,
        string $fiveHoursLater,
        string $sixHoursLater,
        string $dayBefore,
    ): void {
        $snapshots = [$dayBefore => self::snapshot(1, self::METRES_PER_DAY, null)];

        $short = $this->compareIn('Europe/Zurich', $fiveHoursLater, $snapshots);
        $long = $this->compareIn('Europe/Zurich', $sixHoursLater, $snapshots);

        self::assertSame(
            [
                (int) round(5 / 24 * self::METRES_PER_DAY),
                (int) round(5 / 24 * self::METRES_PER_DAY),
                0,
            ],
            $this->figures($short->budget[0]),
            $midnightUtc,
        );
        self::assertNull($short->budget[0]->comparison?->ratio);
        self::assertSame(0.0, $long->budget[0]->comparison?->ratio);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function daylightSavingChanges(): iterable
    {
        // Zurich leaves summer time on 2026-10-25 (a day of 25 hours): its midnight is 22:00 UTC the evening before.
        yield 'end of summer time' => ['2026-10-24 22:00:00', '2026-10-25 03:00:00', '2026-10-25 04:00:00', '2026-10-25'];
        // Zurich enters summer time on 2027-03-28 (a day of 23 hours): its midnight is 23:00 UTC the evening before.
        yield 'start of summer time' => ['2027-03-27 23:00:00', '2027-03-28 04:00:00', '2027-03-28 05:00:00', '2027-03-28'];
    }

    public function testNoReadingSinceMidnightIsFoundAcrossADaylightSavingChange(): void
    {
        // Zurich midnight of 2026-10-25 is 22:00 UTC on the 24th. A reading at 21:00 UTC is before it (23:00 local, in
        // summer time), the clock says 01:00 UTC (02:00 local, the first of two).
        $comparison = $this->compareIn(
            'Europe/Zurich',
            '2026-10-24 21:00:00',
            ['2026-10-25' => self::snapshot(1, 1, null)],
            now: '2026-10-25 01:00:00',
        );

        self::assertTrue($comparison->budget[0]->awaitingReading);
    }

    public function testTodayWithoutASnapshotOfItsOwnDayFallsBackOnTheLatestEarlierOneAndSaysByHowManyDays(): void
    {
        // 2026-07-10 12:00 UTC: today has no snapshot, nor has the 9th; the 8th does, and so does an older one.
        $latest = '2026-07-10 12:00:00';

        $comparison = $this->compare($latest, 36_000_000, [
            '2026-07-08' => self::snapshot(36_500_000, 90_000, 80_000.0),
            '2026-07-01' => self::snapshot(1, 1, 1.0),
        ]);

        // Measured from the midnight of the 8th: 2.5 days, 250,000 m driven; 90,000 m a day expects 225,000 m.
        self::assertSame([250_000, 225_000, 25_000], $this->figures($comparison->budget[0]));
        self::assertSame(2, $comparison->budget[0]->fallbackDays);
        // 80,000 m a day over 2.5 days expects 200,000 m.
        self::assertSame([250_000, 200_000, 50_000], $this->figures($comparison->pace[0]));
        self::assertSame(2, $comparison->pace[0]->fallbackDays);
        // The projection of the 8th, not of the 1st.
        self::assertSame([36_000_000, 36_500_000, -500_000], $this->figures($comparison->projectedEnd[0]));
        self::assertSame(2, $comparison->projectedEnd[0]->fallbackDays);
    }

    public function testTodayKeepsItsOwnSnapshotWhenThereIsOneAndTheOtherWindowsNeverFallBack(): void
    {
        $latest = '2026-07-10 12:00:00';

        $comparison = $this->compare($latest, 36_000_000, [
            '2026-07-10' => self::snapshot(36_000_000, 100_000, null),
            // 8 days before: not the 7 day window's day (the 3rd), so it must not stand in for it.
            '2026-07-02' => self::snapshot(36_000_000, 100_000, null),
        ]);

        self::assertSame(0, $comparison->budget[0]->fallbackDays);
        self::assertNotNull($comparison->budget[0]->comparison);
        self::assertNull($comparison->budget[2]->comparison);
        self::assertSame(0, $comparison->budget[2]->fallbackDays);
    }

    public function testTodayHasNoResultWhenNoSnapshotIsOlderThanIt(): void
    {
        // Only a later snapshot exists: nothing to fall back on.
        $comparison = $this->compare('2026-07-10 12:00:00', 1, ['2026-07-12' => self::snapshot(1, 1, null)]);

        self::assertNull($comparison->budget[0]->comparison);
        self::assertSame(0, $comparison->budget[0]->fallbackDays);
    }

    /**
     * A snapshot that expects exactly what the car drives gives a delta of zero in every window only if the windows
     * start at the owner's real midnights, whatever the zone or daylight saving.
     */
    #[DataProvider('zones')]
    public function testEveryWindowStartsAtTheOwnersLocalMidnight(string $timeZone, string $latest): void
    {
        $timeline = $this->timeline($latest);
        $projection = $this->projection($latest, 1);
        $today = OwnerMidnight::latestAt($projection->asOf, $timeZone);
        $snapshots = [];

        foreach (ComparisonWindow::cases() as $window) {
            $day = $today->localDay->subDays($window->days())->format('Y-m-d');
            $snapshots[$day] = self::snapshot(1, self::METRES_PER_DAY, null);
        }

        $comparison = new PredictionComparer()->compare(
            $timeline,
            $projection,
            $timeZone,
            $snapshots,
            $projection->asOf,
        );

        foreach ($comparison->budget as $row) {
            self::assertNotNull($row->comparison, $row->window->name);
            self::assertSame(0, $row->comparison->delta->metres(), $row->window->name);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function zones(): iterable
    {
        yield 'Zurich across the end of summer time (2026-10-25)' => ['Europe/Zurich', '2026-10-26 12:00:00'];
        yield 'Zurich across the start of summer time (2027-03-28)' => ['Europe/Zurich', '2027-03-29 12:00:00'];
        yield 'Los Angeles' => ['America/Los_Angeles', '2026-07-10 05:00:00'];
        yield 'India (+05:30)' => ['Asia/Kolkata', '2026-07-10 12:00:00'];
        yield 'Nepal (+05:45)' => ['Asia/Kathmandu', '2026-07-10 12:00:00'];
    }

    /**
     * @return array{int, int, int} actual, expected, delta in metres
     */
    private function figures(WindowComparison $row): array
    {
        self::assertNotNull($row->comparison);

        $comparison = $row->comparison;

        return [$comparison->actual->metres(), $comparison->expected->metres(), $comparison->delta->metres()];
    }

    /**
     * @param array<string, SnapshotFigures> $snapshots
     */
    private function compare(
        string $latest,
        int $projectedAtEnd,
        array $snapshots,
        ?string $now = null,
    ): PredictionComparison {
        return $this->compareIn('UTC', $latest, $snapshots, $projectedAtEnd, $now);
    }

    /**
     * @param array<string, SnapshotFigures> $snapshots
     */
    private function compareIn(
        string $timeZone,
        string $latest,
        array $snapshots,
        int $projectedAtEnd = 1,
        ?string $now = null,
        bool $todayWholeDay = false,
    ): PredictionComparison {
        return new PredictionComparer()->compare(
            $this->timeline($latest),
            $this->projection($latest, $projectedAtEnd),
            $timeZone,
            $snapshots,
            CarbonImmutable::parse($now ?? $latest, 'UTC'),
            $todayWholeDay,
        );
    }

    private static function snapshot(int $projectedAtEnd, int $dailyBudget, ?float $metresPerDay): SnapshotFigures
    {
        return new SnapshotFigures(
            Distance::fromMetres($projectedAtEnd),
            Distance::fromMetres($dailyBudget),
            null === $metresPerDay ? null : Pace::fromMetresPerDay($metresPerDay),
        );
    }

    private function timeline(string $latest): OdometerTimeline
    {
        $start = CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC');
        $at = CarbonImmutable::parse($latest, 'UTC');
        $metres = (int) round(($at->getTimestamp() - $start->getTimestamp()) / 86_400 * self::METRES_PER_DAY);

        $sample = new OdometerSample($at, Distance::fromMetres($metres));

        return OdometerTimeline::fromSamples($start, Distance::fromMetres(0), [$sample], $at->addDay());
    }

    private function projection(string $latest, int $projectedAtEnd): Projection
    {
        $pace = Pace::fromMetresPerDay((float) self::METRES_PER_DAY);

        return new Projection(
            CarbonImmutable::parse($latest, 'UTC'),
            Distance::fromMetres(1),
            Distance::fromMetres(1),
            DistanceDelta::between(Distance::fromMetres(1), Distance::fromMetres(1)),
            $pace,
            null,
            $pace,
            Distance::fromMetres($projectedAtEnd),
        );
    }
}
