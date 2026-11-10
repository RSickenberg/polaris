<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Lease\Domain;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Lease\Domain\OdometerTimeline;
use Polaris\Lease\Domain\QuietHours;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\OdometerSample;

#[CoversClass(OdometerTimeline::class)]
final class OdometerTimelineTest extends TestCase
{
    private const string START = '2026-01-01 00:00:00';

    public function testTheOdometerIsLinearBetweenTwoSamples(): void
    {
        $timeline = $this->timeline([['2026-01-11 00:00:00', 1_000_000], ['2026-01-21 00:00:00', 3_000_000]]);

        self::assertSame(0.0, $timeline->odometerAt($this->at(self::START)));
        self::assertSame(500_000.0, $timeline->odometerAt($this->at('2026-01-06 00:00:00')));
        self::assertSame(1_000_000.0, $timeline->odometerAt($this->at('2026-01-11 00:00:00')));
        self::assertSame(2_000_000.0, $timeline->odometerAt($this->at('2026-01-16 00:00:00')));
        self::assertSame(3_000_000.0, $timeline->odometerAt($this->at('2026-01-21 00:00:00')));
    }

    public function testQuietHoursKeepTheOdometerStillAtNight(): void
    {
        // 21:00 on 1 March at 10,000 km, 21:00 on 2 March at 10,060 km, all of it driven in daytime.
        $samples = [['2026-03-01 21:00:00', 10_000_000], ['2026-03-02 21:00:00', 10_060_000]];
        $midnight = $this->at('2026-03-02 00:00:00');

        // Linear: 3 h of 24 are before midnight, so 7.5 km are counted on 1 March.
        self::assertSame(10_007_500.0, $this->timeline($samples, null, 10_000_000)->odometerAt($midnight));
        // Quiet 20:00 to 06:00, the 21:00 reading inside it: nothing is driven before midnight.
        self::assertSame(
            10_000_000.0,
            $this->timeline($samples, QuietHours::between('20:00', '06:00', 'UTC'), 10_000_000)->odometerAt($midnight)
        );
        // Quiet 22:00 to 06:00: 1 h + 15 h = 16 active hours, 1 h of them before midnight: 60 km / 16 = 3.75 km.
        self::assertSame(
            10_003_750.0,
            $this->timeline($samples, QuietHours::between('22:00', '06:00', 'UTC'), 10_000_000)->odometerAt($midnight)
        );
    }

    public function testQuietHoursStillReachTheSamples(): void
    {
        $quiet = QuietHours::between('22:00', '06:00', 'UTC');
        $timeline = $this->timeline([['2026-03-01 21:00:00', 10_000_000], ['2026-03-02 21:00:00', 10_060_000]], $quiet, 10_000_000);

        self::assertSame(10_000_000.0, $timeline->odometerAt($this->at('2026-03-01 21:00:00')));
        self::assertSame(10_060_000.0, $timeline->odometerAt($this->at('2026-03-02 21:00:00')));
        // Never decreasing across the quiet hours.
        self::assertSame(
            $timeline->odometerAt($this->at('2026-03-01 22:00:00')),
            $timeline->odometerAt($this->at('2026-03-02 06:00:00')),
        );
    }

    public function testTwoReadingsInsideOneQuietPeriodFallBackToLinear(): void
    {
        $quiet = QuietHours::between('22:00', '06:00', 'UTC');
        $timeline = $this->timeline([['2026-03-01 23:00:00', 1_000_000], ['2026-03-02 01:00:00', 1_400_000]], $quiet, 1_000_000);

        self::assertSame(1_200_000.0, $timeline->odometerAt($this->at('2026-03-02 00:00:00')));
    }

    public function testAReadingInsideTheQuietPeriodHoldsTheOdometerUntilTheNextActiveHour(): void
    {
        $quiet = QuietHours::between('22:00', '06:00', 'UTC');
        // The first reading at 23:00 (quiet), the next at 12:00 the day after: 6 active hours, 12:00 - 06:00.
        $timeline = $this->timeline([['2026-03-01 23:00:00', 5_000_000], ['2026-03-02 12:00:00', 5_060_000]], $quiet, 5_000_000);

        self::assertSame(5_000_000.0, $timeline->odometerAt($this->at('2026-03-02 06:00:00')));
        self::assertSame(5_030_000.0, $timeline->odometerAt($this->at('2026-03-02 09:00:00')));
    }

    public function testQuietHoursFollowTheOwnersDaylightSavingNight(): void
    {
        // Zurich, clocks forward on 2026-03-29: the night 22:00 to 06:00 local lasts 7 hours, not 8.
        $zurich = 'Europe/Zurich';
        $timeline = OdometerTimeline::fromSamples(
            CarbonImmutable::parse(self::START, 'UTC'),
            Distance::fromMetres(0),
            [
                new OdometerSample(
                    CarbonImmutable::parse('2026-03-28 21:00:00', $zurich)->utc(),
                    Distance::fromMetres(1_000_000),
                ),
                new OdometerSample(
                    CarbonImmutable::parse('2026-03-29 13:00:00', $zurich)->utc(),
                    Distance::fromMetres(1_600_000),
                ),
            ],
            CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC'),
            QuietHours::between('22:00', '06:00', $zurich),
        );

        // From 21:00 to 13:00 the next day: 1 h before 22:00, then 7 h from 06:00 to 13:00. That is 8 active hours
        // for 600 km, so 75 km an hour. At 06:00, the end of the quiet night, only the first hour is done: 75 km.
        self::assertEqualsWithDelta(
            1_075_000.0,
            $timeline->odometerAt(CarbonImmutable::parse('2026-03-29 06:00:00', $zurich)->getTimestamp()),
            0.001,
        );
    }

    public function testItCoversFromTheLeaseStartToTheLatestSample(): void
    {
        $timeline = $this->timeline([['2026-01-11 00:00:00', 1_000_000]]);

        self::assertTrue($timeline->covers($this->at(self::START)));
        self::assertTrue($timeline->covers($this->at('2026-01-11 00:00:00')));
        self::assertFalse($timeline->covers($this->at('2025-12-31 23:59:59')));
        self::assertFalse($timeline->covers($this->at('2026-01-11 00:00:01')));
        self::assertSame($this->at('2026-01-11 00:00:00'), $timeline->latestInstant());
    }

    public function testWithoutSamplesAfterTheStartThereAreNone(): void
    {
        $timeline = $this->timeline([['2025-12-01 00:00:00', 500_000], [self::START, 0]]);

        self::assertFalse($timeline->hasSamples());
        self::assertSame($this->at(self::START), $timeline->latestInstant());
    }

    public function testAnInstantOutsideTheTimelineIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->timeline([['2026-01-11 00:00:00', 1_000_000]])->odometerAt($this->at('2026-02-01 00:00:00'));
    }

    public function testSamplesMayComeInAnyOrder(): void
    {
        $timeline = $this->timeline([['2026-01-21 00:00:00', 3_000_000], ['2026-01-11 00:00:00', 1_000_000]]);

        self::assertSame(2_000_000.0, $timeline->odometerAt($this->at('2026-01-16 00:00:00')));
    }

    public function testASampleInTheFutureIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->timeline([['2026-07-02 00:00:00', 1_000_000]]);
    }

    public function testAnOdometerLowerThanAnEarlierOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->timeline([['2026-01-11 00:00:00', 2_000_000], ['2026-01-21 00:00:00', 1_000_000]]);
    }

    public function testTwoDifferentOdometersAtTheSameSecondAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->timeline([['2026-01-11 00:00:00', 1_000_000], ['2026-01-11 00:00:00', 1_000_001]]);
    }

    /**
     * @param list<array{string, int}> $samples
     */
    private function timeline(array $samples, ?QuietHours $quietHours = null, int $startOdometer = 0): OdometerTimeline
    {
        return OdometerTimeline::fromSamples(
            CarbonImmutable::parse(self::START, 'UTC'),
            Distance::fromMetres($startOdometer),
            array_map(
                static fn (array $sample): OdometerSample => new OdometerSample(
                    CarbonImmutable::parse($sample[0], 'UTC'),
                    Distance::fromMetres($sample[1]),
                ),
                $samples,
            ),
            CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC'),
            $quietHours,
        );
    }

    private function at(string $instant): int
    {
        return CarbonImmutable::parse($instant, 'UTC')->getTimestamp();
    }
}
