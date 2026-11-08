<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Lease\Domain;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Lease\Domain\OdometerTimeline;
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
    private function timeline(array $samples): OdometerTimeline
    {
        return OdometerTimeline::fromSamples(
            CarbonImmutable::parse(self::START, 'UTC'),
            Distance::fromMetres(0),
            array_map(
                static fn (array $sample): OdometerSample => new OdometerSample(
                    CarbonImmutable::parse($sample[0], 'UTC'),
                    Distance::fromMetres($sample[1]),
                ),
                $samples,
            ),
            CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC'),
        );
    }

    private function at(string $instant): int
    {
        return CarbonImmutable::parse($instant, 'UTC')->getTimestamp();
    }
}
