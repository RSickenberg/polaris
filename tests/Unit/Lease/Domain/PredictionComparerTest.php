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
        self::assertSame([750_000, 750_000, 0], $this->figures($comparison->budget[1]));
        self::assertSame(0.0, $comparison->budget[1]->comparison?->ratio);
        self::assertNull($comparison->budget[2]->comparison, 'No snapshot 14 days before.');
        self::assertSame([3_050_000, 0, 3_050_000], $this->figures($comparison->budget[3]));
        self::assertNull($comparison->budget[3]->comparison?->ratio, 'Nothing expected: the distance only.');

        // Pace: 80,000 m a day over 12 hours expects 40,000 m.
        self::assertSame([50_000, 40_000, 10_000], $this->figures($comparison->pace[0]));
        self::assertEqualsWithDelta(0.25, $comparison->pace[0]->comparison?->ratio, 1e-12);
        self::assertNull($comparison->pace[1]->comparison, 'The snapshot of 7 days before has no pace.');
        self::assertNull($comparison->pace[2]->comparison);
        self::assertSame([3_050_000, 0, 3_050_000], $this->figures($comparison->pace[3]));

        // Projected end: 36,000,000 m now against 36,500,000 m projected this morning.
        self::assertSame([36_000_000, 36_500_000, -500_000], $this->figures($comparison->projectedEnd[0]));
        self::assertEqualsWithDelta(-500_000 / 36_500_000, $comparison->projectedEnd[0]->comparison?->ratio, 1e-12);
        self::assertSame([36_000_000, 36_000_000, 0], $this->figures($comparison->projectedEnd[1]));
        self::assertNull($comparison->projectedEnd[2]->comparison);
    }

    public function testTheWindowsAreInTheOrderOfTheEnum(): void
    {
        $latest = '2026-07-10 12:00:00';

        $comparison = $this->compare($latest, 36_000_000, []);

        foreach ([$comparison->budget, $comparison->pace, $comparison->projectedEnd] as $rows) {
            self::assertSame(
                [
                    ComparisonWindow::Today,
                    ComparisonWindow::LastWeek,
                    ComparisonWindow::LastTwoWeeks,
                    ComparisonWindow::LastMonth,
                ],
                array_map(static fn (WindowComparison $row): ComparisonWindow => $row->window, $rows),
            );
            self::assertSame(
                [null, null, null, null],
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

        self::assertNull($comparison->budget[1]->comparison);
        self::assertNull($comparison->pace[1]->comparison);
    }

    public function testTodayStartsAtTheLastMidnightBeforeTheLatestReadingNotBeforeTheClock(): void
    {
        // The latest reading is from 3 July, days before "now": today is the 3rd.
        $latest = '2026-07-03 06:00:00';

        $comparison = $this->compare($latest, 1, [
            '2026-07-03' => self::snapshot(1, 100_000, null),
            '2026-07-10' => self::snapshot(1, 1, null),
        ]);

        // 6 hours since midnight: 25,000 m driven, 25,000 m expected.
        self::assertSame([25_000, 25_000, 0], $this->figures($comparison->budget[0]));
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
        self::assertNull($comparison->budget[1]->comparison);
        self::assertSame(0, $comparison->budget[1]->fallbackDays);
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

        $comparison = new PredictionComparer()->compare($timeline, $projection, $timeZone, $snapshots);

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
    private function compare(string $latest, int $projectedAtEnd, array $snapshots): PredictionComparison
    {
        return new PredictionComparer()->compare(
            $this->timeline($latest),
            $this->projection($latest, $projectedAtEnd),
            'UTC',
            $snapshots,
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
