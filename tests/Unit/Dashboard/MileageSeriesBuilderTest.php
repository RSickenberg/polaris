<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Dashboard;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Dashboard\Domain\MileageForecast;
use Polaris\Dashboard\Domain\MileagePoint;
use Polaris\Dashboard\Domain\MileageSeries;
use Polaris\Dashboard\Domain\MileageSeriesBuilder;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\OdometerSample;

#[CoversClass(MileageSeriesBuilder::class)]
#[CoversClass(MileagePoint::class)]
final class MileageSeriesBuilderTest extends TestCase
{
    private const string START = '2026-01-01 00:00:00';
    private const string END = '2027-01-01 00:00:00';

    public function testTheActualCurveStartsAtTheLeaseStartWithNothingDriven(): void
    {
        $series = $this->build([]);

        self::assertSame([[self::START, 0]], self::points($series->actual));
    }

    public function testTheActualCurveIsTheDistanceDrivenSinceTheStartOdometer(): void
    {
        $series = $this->build([
            $this->sample('2026-04-01 10:00:00', 1_500),
            $this->sample('2026-02-01 10:00:00', 1_200),
        ], startOdometerKm: 1_000);

        self::assertSame(
            [[self::START, 0], ['2026-02-01 10:00:00', 200_000], ['2026-04-01 10:00:00', 500_000]],
            self::points($series->actual),
        );
    }

    public function testReadingsAtOrBeforeTheLeaseStartAreIgnored(): void
    {
        $series = $this->build([
            $this->sample('2025-12-01 10:00:00', 400),
            $this->sample(self::START, 500),
            $this->sample('2026-03-01 10:00:00', 1_000),
        ], startOdometerKm: 500);

        self::assertSame([[self::START, 0], ['2026-03-01 10:00:00', 500_000]], self::points($series->actual));
    }

    public function testASingleReadingGivesTwoPoints(): void
    {
        $series = $this->build([$this->sample('2026-03-01 10:00:00', 3_000)]);

        self::assertCount(2, $series->actual);
    }

    public function testReadingsPastTheLeaseEndAreKept(): void
    {
        $series = $this->build([
            $this->sample('2026-12-01 10:00:00', 14_000),
            $this->sample('2027-01-05 10:00:00', 15_200),
        ]);

        self::assertSame(['2027-01-05 10:00:00', 15_200_000], self::points($series->actual)[2]);
    }

    public function testReadingsOfTheSameSecondCollapseToOnePoint(): void
    {
        $series = $this->build([
            $this->sample('2026-03-01 10:00:00', 3_000),
            $this->sample('2026-03-01 10:00:00', 3_000),
        ]);

        self::assertCount(2, $series->actual);
    }

    public function testTheAllowedLineIsLinearFromNothingToTheWholeAllowance(): void
    {
        $series = $this->build([$this->sample('2026-03-01 10:00:00', 3_000)]);

        self::assertSame([[self::START, 0], [self::END, 15_000_000]], self::points($series->allowed));
    }

    public function testTheProjectionRunsFromTheLatestReadingToTheLeaseEnd(): void
    {
        $series = $this->build(
            [$this->sample('2026-07-02 11:00:00', 10_000)],
            forecast: new MileageForecast(
                CarbonImmutable::parse('2026-07-02 11:00:00', 'UTC'),
                Distance::fromKilometres(10_000),
                Distance::fromKilometres(19_900),
            ),
        );

        self::assertTrue($series->hasProjection());
        self::assertSame([['2026-07-02 11:00:00', 10_000_000], [self::END, 19_900_000]], self::points($series->projection));
    }

    public function testWithoutAForecastThereIsNoProjection(): void
    {
        $series = $this->build([$this->sample('2027-01-05 10:00:00', 16_000)]);

        self::assertFalse($series->hasProjection());
        self::assertSame([], $series->projection);
    }

    public function testAReadingBelowTheStartOdometerIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->build([$this->sample('2026-03-01 10:00:00', 400)], startOdometerKm: 500);
    }

    /**
     * @param list<OdometerSample> $samples
     */
    private function build(array $samples, int $startOdometerKm = 0, ?MileageForecast $forecast = null): MileageSeries
    {
        return new MileageSeriesBuilder()->build(
            CarbonImmutable::parse(self::START, 'UTC'),
            CarbonImmutable::parse(self::END, 'UTC'),
            Distance::fromKilometres($startOdometerKm),
            Distance::fromKilometres(15_000),
            $samples,
            $forecast,
        );
    }

    private function sample(string $at, int $km): OdometerSample
    {
        return new OdometerSample(CarbonImmutable::parse($at, 'UTC'), Distance::fromKilometres($km));
    }

    /**
     * @param list<MileagePoint> $points
     *
     * @return list<array{string, int}>
     */
    private static function points(array $points): array
    {
        return array_map(
            static fn (MileagePoint $point): array => [$point->at->format('Y-m-d H:i:s'), $point->distance->metres()],
            $points,
        );
    }
}
