<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Lease\Domain;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\Projection;
use Polaris\Lease\Domain\ProjectionCalculator;
use Polaris\Lease\Domain\RecentPaceWeight;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\OdometerSample;
use Symfony\Component\Clock\MockClock;

/**
 * Unless a case says otherwise: a 12-month lease from 2026-01-01 to 2027-01-01
 * (exclusive), 365 days = 31,536,000 s; an allowance of 36,500 km (100 km a day);
 * a start odometer of 10,000 km; a recent pace weight of 0.6.
 *
 * Day n is 2026-01-01 plus n days. Distances are in metres, paces in metres per day.
 */
#[CoversClass(ProjectionCalculator::class)]
#[CoversClass(Projection::class)]
final class ProjectionCalculatorTest extends TestCase
{
    private const int START_ODOMETER = 10_000_000;

    /**
     * @return iterable<string, array{LeaseTerm, int, string, list<array{string, int}>, array{string, int, int, int, float, float|null, float, int}}>
     */
    public static function projections(): iterable
    {
        // Day 20: driven 12,000,000 - 10,000,000 = 2,000,000.
        // Allowed 36,500,000 x 20 / 365 = 2,000,000, margin 0.
        // Overall 2,000,000 / 20 = 100,000. Under 30 days: no recent pace, blended = overall.
        // Projected 2,000,000 + 100,000 x (365 - 20) = 36,500,000.
        yield 'younger than 30 days: overall pace only' => [
            self::term('2026-01-01', '2027-01-01'), 36_500, '2026-01-21 12:00',
            [['2026-01-21 00:00', 12_000_000]],
            ['2026-01-21 00:00', 2_000_000, 2_000_000, 0, 100_000.0, null, 100_000.0, 36_500_000],
        ];

        // Day 30: driven 3,600,000. Allowed 36,500,000 x 30 / 365 = 3,000,000, margin -600,000.
        // Overall 3,600,000 / 30 = 120,000. The window starts at the lease start (10,000,000):
        // recent (13,600,000 - 10,000,000) / 30 = 120,000, blended 120,000.
        // Projected 3,600,000 + 120,000 x 335 = 43,800,000.
        yield 'exactly 30 days: the window starts at the lease start' => [
            self::term('2026-01-01', '2027-01-01'), 36_500, '2026-01-31 12:00',
            [['2026-01-31 00:00', 13_600_000]],
            ['2026-01-31 00:00', 3_600_000, 3_000_000, -600_000, 120_000.0, 120_000.0, 120_000.0, 43_800_000],
        ];

        // Day 100: driven 9,000,000. Allowed 36,500,000 x 100 / 365 = 10,000,000, margin 1,000,000.
        // Overall 9,000,000 / 100 = 90,000. Recent, from day 70: (19,000,000 - 15,400,000) / 30 = 120,000.
        // Blended 0.6 x 120,000 + 0.4 x 90,000 = 108,000.
        // Projected 9,000,000 + 108,000 x 265 = 37,620,000.
        yield 'under the allowance, speeding up' => [
            self::term('2026-01-01', '2027-01-01'), 36_500, '2026-04-11 12:00',
            [['2026-03-12 00:00', 15_400_000], ['2026-04-11 00:00', 19_000_000]],
            ['2026-04-11 00:00', 9_000_000, 10_000_000, 1_000_000, 90_000.0, 120_000.0, 108_000.0, 37_620_000],
        ];

        // Same as above, but no sample on day 70: the window start is interpolated between
        // day 65 (14,700,000) and day 75 (16,100,000): 14,700,000 + 1,400,000 x 5 / 10 = 15,400,000.
        yield 'window start interpolated between two samples' => [
            self::term('2026-01-01', '2027-01-01'), 36_500, '2026-04-11 12:00',
            [['2026-03-07 00:00', 14_700_000], ['2026-03-17 00:00', 16_100_000], ['2026-04-11 00:00', 19_000_000]],
            ['2026-04-11 00:00', 9_000_000, 10_000_000, 1_000_000, 90_000.0, 120_000.0, 108_000.0, 37_620_000],
        ];

        // Day 100: driven 11,000,000. Allowed 10,000,000, margin -1,000,000.
        // Overall 110,000. Recent (21,000,000 - 18,000,000) / 30 = 100,000.
        // Blended 0.6 x 100,000 + 0.4 x 110,000 = 104,000.
        // Projected 11,000,000 + 104,000 x 265 = 38,560,000.
        yield 'over the allowance, slowing down' => [
            self::term('2026-01-01', '2027-01-01'), 36_500, '2026-04-11 12:00',
            [['2026-03-12 00:00', 18_000_000], ['2026-04-11 00:00', 21_000_000]],
            ['2026-04-11 00:00', 11_000_000, 10_000_000, -1_000_000, 110_000.0, 100_000.0, 104_000.0, 38_560_000],
        ];

        // The case above with the day-100 sample, but the lease is written with its last
        // day (2026-12-31, inclusive): the exclusive end is still 2027-01-01, same result.
        yield 'inclusive end date' => [
            self::term('2026-01-01', '2026-12-31', EndDateConvention::Inclusive), 36_500, '2026-04-11 12:00',
            [['2026-03-12 00:00', 15_400_000], ['2026-04-11 00:00', 19_000_000]],
            ['2026-04-11 00:00', 9_000_000, 10_000_000, 1_000_000, 90_000.0, 120_000.0, 108_000.0, 37_620_000],
        ];

        // Leap year: 2028-01-01 to 2029-01-01 is 366 days; 36,600 km is 100 km a day.
        // 2028-02-29 is day 59: driven 5,900,000, allowed 36,600,000 x 59 / 366 = 5,900,000, margin 0.
        // Overall 5,900,000 / 59 = 100,000. Window start day 29, interpolated from the lease start:
        // 10,000,000 + 5,900,000 x 29 / 59 = 12,900,000; recent (15,900,000 - 12,900,000) / 30 = 100,000.
        // Projected 5,900,000 + 100,000 x (366 - 59) = 36,600,000.
        yield 'leap year' => [
            self::term('2028-01-01', '2029-01-01'), 36_600, '2028-02-29 12:00',
            [['2028-02-29 00:00', 15_900_000]],
            ['2028-02-29 00:00', 5_900_000, 5_900_000, 0, 100_000.0, 100_000.0, 100_000.0, 36_600_000],
        ];

        // The lease is over, and the latest sample (day 375) comes after its end (day 365):
        // the odometer at the end is 44,500,000 + 3,000,000 x (365 - 345) / (375 - 345) = 46,500,000.
        // Driven 36,500,000, allowed the whole 36,500,000, margin 0. Overall 36,500,000 / 365 = 100,000.
        // Window start day 335, between the lease start and day 345:
        // 10,000,000 + 34,500,000 x 335 / 345 = 43,500,000; recent (46,500,000 - 43,500,000) / 30 = 100,000.
        // No time left: projected = driven.
        yield 'lease over, sample after the end' => [
            self::term('2026-01-01', '2027-01-01'), 36_500, '2027-03-01 00:00',
            [['2026-12-12 00:00', 44_500_000], ['2027-01-11 00:00', 47_500_000]],
            ['2027-01-01 00:00', 36_500_000, 36_500_000, 0, 100_000.0, 100_000.0, 100_000.0, 36_500_000],
        ];

        // The lease is over, but the latest sample is day 300: figures stay at day 300.
        // Driven 30,000,000, allowed 36,500,000 x 300 / 365 = 30,000,000, margin 0. Overall 100,000.
        // Recent (40,000,000 - 38,000,000) / 30 = 66,666.67 (full precision).
        // Blended 0.6 x 66,666.67 + 0.4 x 100,000 = 40,000 + 40,000 = 80,000.
        // Projected 30,000,000 + 80,000 x 65 = 35,200,000.
        yield 'lease over, no sample after the end' => [
            self::term('2026-01-01', '2027-01-01'), 36_500, '2027-02-01 00:00',
            [['2026-09-28 00:00', 38_000_000], ['2026-10-28 00:00', 40_000_000]],
            ['2026-10-28 00:00', 30_000_000, 30_000_000, 0, 100_000.0, 2_000_000 / 30, 80_000.0, 35_200_000],
        ];

        // 54 s after the start: allowed 36,500,000 x 54 / 31,536,000 = 62.5, rounded half up to 63.
        // Driven 1, margin 63 - 1 = 62. Overall 1 x 86,400 / 54 = 1,600.
        // Projected 1 + 1,600 x (31,536,000 - 54) / 86,400 = 1 + 583,999 = 584,000.
        yield 'allowed to date rounded half up' => [
            self::term('2026-01-01', '2027-01-01'), 36_500, '2026-01-01 00:01:00',
            [['2026-01-01 00:00:54', 10_000_001]],
            ['2026-01-01 00:00:54', 1, 63, 62, 1_600.0, null, 1_600.0, 584_000],
        ];

        // Day 2: driven 1, allowed 36,500,000 x 2 / 365 = 200,000, margin 199,999. Overall 0.5.
        // Projected 1 + 0.5 x 363 = 182.5, rounded half up to 183.
        yield 'projection rounded half up' => [
            self::term('2026-01-01', '2027-01-01'), 36_500, '2026-01-03 12:00',
            [['2026-01-03 00:00', 10_000_001]],
            ['2026-01-03 00:00', 1, 200_000, 199_999, 0.5, null, 0.5, 183],
        ];

        // Samples out of order, a duplicate, and samples at or before the lease start (even
        // one below the start odometer) are ignored: same result as "under the allowance".
        yield 'unordered samples, a duplicate and samples before the start' => [
            self::term('2026-01-01', '2027-01-01'), 36_500, '2026-04-11 12:00',
            [
                ['2026-04-11 00:00', 19_000_000],
                ['2025-12-15 00:00', 9_000_000],
                ['2026-03-12 00:00', 15_400_000],
                ['2026-01-01 00:00', 10_000_500],
                ['2026-04-11 00:00', 19_000_000],
            ],
            ['2026-04-11 00:00', 9_000_000, 10_000_000, 1_000_000, 90_000.0, 120_000.0, 108_000.0, 37_620_000],
        ];
    }

    /**
     * @param list<array{string, int}>                                    $samples
     * @param array{string, int, int, int, float, float|null, float, int} $expected
     */
    #[DataProvider('projections')]
    public function testProjection(LeaseTerm $term, int $allowanceKm, string $now, array $samples, array $expected): void
    {
        [$asOf, $driven, $allowedToDate, $margin, $overallPace, $recentPace, $blendedPace, $projectedAtEnd] = $expected;

        $projection = self::calculator($now)->calculate(
            $term,
            Allowance::ofTotal(Distance::fromKilometres($allowanceKm)),
            Distance::fromMetres(self::START_ODOMETER),
            self::samples($samples),
        );

        self::assertNotNull($projection);
        self::assertSame(new CarbonImmutable($asOf, 'UTC')->toIso8601ZuluString(), $projection->asOf->toIso8601ZuluString());
        self::assertSame(0, $projection->asOf->getOffset());
        self::assertSame($driven, $projection->driven->metres());
        self::assertSame($allowedToDate, $projection->allowedToDate->metres());
        self::assertSame($margin, $projection->margin->metres());
        self::assertEqualsWithDelta($overallPace, $projection->overallPace->metresPerDay(), 1e-6);

        if (null === $recentPace) {
            self::assertNull($projection->recentPace);
        } else {
            self::assertNotNull($projection->recentPace);
            self::assertEqualsWithDelta($recentPace, $projection->recentPace->metresPerDay(), 1e-6);
        }

        self::assertEqualsWithDelta($blendedPace, $projection->blendedPace->metresPerDay(), 1e-6);
        self::assertSame($projectedAtEnd, $projection->projectedAtEnd->metres());
    }

    /**
     * @return iterable<string, array{int, float, int}>
     */
    public static function weights(): iterable
    {
        // "Under the allowance, speeding up": recent 120,000, overall 90,000, driven 9,000,000, 265 days left.
        // Weight 0: blended 90,000, projected 9,000,000 + 90,000 x 265 = 32,850,000.
        yield 'overall only' => [0, 90_000.0, 32_850_000];
        // Weight 1: blended 120,000, projected 9,000,000 + 120,000 x 265 = 40,800,000.
        yield 'recent only' => [10_000, 120_000.0, 40_800_000];
    }

    #[DataProvider('weights')]
    public function testWeightIsConfigurable(int $basisPoints, float $blendedPace, int $projectedAtEnd): void
    {
        $projection = self::calculator('2026-04-11 12:00', $basisPoints)->calculate(
            self::term('2026-01-01', '2027-01-01'),
            Allowance::ofTotal(Distance::fromKilometres(36_500)),
            Distance::fromMetres(self::START_ODOMETER),
            self::samples([['2026-03-12 00:00', 15_400_000], ['2026-04-11 00:00', 19_000_000]]),
        );

        self::assertNotNull($projection);
        self::assertSame($blendedPace, $projection->blendedPace->metresPerDay());
        self::assertSame($projectedAtEnd, $projection->projectedAtEnd->metres());
    }

    /**
     * @return iterable<string, array{string, list<array{string, int}>}>
     */
    public static function withoutData(): iterable
    {
        yield 'no sample' => ['2026-04-11 12:00', []];
        yield 'clock before the lease start' => ['2025-12-31 12:00', [['2025-12-30 00:00', 9_990_000]]];
        yield 'samples at or before the start only' => ['2026-04-11 12:00', [['2025-12-30 00:00', 9_990_000], ['2026-01-01 00:00', 10_000_000]]];
    }

    /**
     * @param list<array{string, int}> $samples
     */
    #[DataProvider('withoutData')]
    public function testNoSampleInsideTheLeaseGivesNoProjection(string $now, array $samples): void
    {
        self::assertNull(self::calculator($now)->calculate(
            self::term('2026-01-01', '2027-01-01'),
            Allowance::ofTotal(Distance::fromKilometres(36_500)),
            Distance::fromMetres(self::START_ODOMETER),
            self::samples($samples),
        ));
    }

    /**
     * @return iterable<string, array{list<array{string, int}>, string}>
     */
    public static function invalidSamples(): iterable
    {
        yield 'sample in the future' => [[['2026-04-11 12:00:01', 19_000_000]], 'in the future'];
        yield 'lower than the start odometer' => [[['2026-01-21 00:00', 9_999_999]], 'lower than an earlier one'];
        yield 'lower than an earlier sample' => [[['2026-03-12 00:00', 15_400_000], ['2026-04-11 00:00', 15_399_999]], 'lower than an earlier one'];
        yield 'two odometers at the same second' => [[['2026-04-11 00:00', 19_000_000], ['2026-04-11 00:00', 19_000_001]], 'disagree'];
    }

    /**
     * @param list<array{string, int}> $samples
     */
    #[DataProvider('invalidSamples')]
    public function testInvalidSamplesAreRejected(array $samples, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::calculator('2026-04-11 12:00')->calculate(
            self::term('2026-01-01', '2027-01-01'),
            Allowance::ofTotal(Distance::fromKilometres(36_500)),
            Distance::fromMetres(self::START_ODOMETER),
            self::samples($samples),
        );
    }

    public function testAllowanceTooLargeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('too large');

        self::calculator('2026-04-11 12:00')->calculate(
            self::term('2026-01-01', '2027-01-01'),
            Allowance::ofTotal(Distance::fromMetres(\PHP_INT_MAX)),
            Distance::fromMetres(self::START_ODOMETER),
            self::samples([['2026-04-11 00:00', 19_000_000]]),
        );
    }

    private static function calculator(string $now, int $recentPaceWeight = 6_000): ProjectionCalculator
    {
        return new ProjectionCalculator(
            new MockClock(new \DateTimeImmutable($now, new \DateTimeZone('UTC'))),
            RecentPaceWeight::fromBasisPoints($recentPaceWeight),
        );
    }

    private static function term(string $start, string $end, EndDateConvention $convention = EndDateConvention::Exclusive): LeaseTerm
    {
        return new LeaseTerm(new CarbonImmutable($start, 'UTC'), new CarbonImmutable($end, 'UTC'), $convention);
    }

    /**
     * @param list<array{string, int}> $samples
     *
     * @return list<OdometerSample>
     */
    private static function samples(array $samples): array
    {
        return array_map(
            static fn (array $sample): OdometerSample => new OdometerSample(new CarbonImmutable($sample[0], 'UTC'), Distance::fromMetres($sample[1])),
            $samples,
        );
    }
}
