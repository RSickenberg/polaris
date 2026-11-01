<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Lease\Domain;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseAssessment;
use Polaris\Lease\Domain\LeaseAssessor;
use Polaris\Lease\Domain\LeaseStatus;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\NoData;
use Polaris\Lease\Domain\ProjectionCalculator;
use Polaris\Lease\Domain\RecentPaceWeight;
use Polaris\Lease\Domain\RiskLevel;
use Polaris\Lease\Domain\Tolerance;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\Money;
use Polaris\Shared\Domain\OdometerSample;
use Symfony\Component\Clock\MockClock;

/**
 * Unless a case says otherwise: a 12-month lease from 2026-01-01 to 2027-01-01 (365 days),
 * an allowance A of 36,500 km (36,500,000 m), a start odometer of 10,000 km, an excess of
 * 0.45 CHF per km, no tolerance, a recent pace weight of 0.6.
 *
 * Day n is 2026-01-01 plus n days. Distances are in metres.
 */
#[CoversClass(LeaseAssessor::class)]
#[CoversClass(LeaseAssessment::class)]
final class LeaseAssessorTest extends TestCase
{
    public function testOverTheAllowance(): void
    {
        // Day 100, clock at noon: driven 9,000,000, projected 37,620,000 (see ProjectionCalculatorTest).
        // Excess 37,620,000 - 36,500,000 = 1,120,000 m = 1,120 km x 0.45 = 504.00 CHF.
        // Remaining 36,500,000 - 9,000,000 = 27,500,000 m over 265 days (from the reading, not the clock):
        //   day 27,500,000 / 265 = 103,773.6 -> 103,774; week x 7 = 726,415.1 -> 726,415;
        //   month 27,500,000 x 365 / (12 x 265) = 3,156,446.5 -> 3,156,447.
        // To cut per week: 1,120,000 x 7 / 265 = 29,584.9 -> 29,585.
        $assessment = self::assess('2026-04-11 12:00', [['2026-03-12 00:00', 15_400_000], ['2026-04-11 00:00', 19_000_000]]);

        self::assertSame(LeaseStatus::Active, $assessment->status);
        self::assertSame(RiskLevel::Over, $assessment->riskLevel);
        self::assertSame(27_500_000, $assessment->remaining->metres());
        self::assertSame(103_774, $assessment->budget->perDay->metres());
        self::assertSame(726_415, $assessment->budget->perWeek->metres());
        self::assertSame(3_156_447, $assessment->budget->perMonth->metres());
        self::assertSame(1_120_000, $assessment->projectedExcess->metres());
        self::assertSame(50_400, $assessment->projectedExcessCost->minorAmount());
        self::assertSame(Currency::CHF, $assessment->projectedExcessCost->currency());
        self::assertNotNull($assessment->weeklyCut);
        self::assertSame(29_585, $assessment->weeklyCut->metres());
        // At the current pace: (37,620,000 - 9,000,000) = 28,620,000 over 265 days = 108,000 a day, 756,000 a week.
        // The weekly cut is that week minus the budget week: 756,000 - 726,415 = 29,585.
        self::assertSame(108_000, $assessment->currentPace->perDay->metres());
        self::assertSame(756_000, $assessment->currentPace->perWeek->metres());
        self::assertSame($assessment->weeklyCut->metres(), $assessment->currentPace->perWeek->metres() - $assessment->budget->perWeek->metres());
    }

    public function testFarUnderTheAllowanceHasRoomAndNoCost(): void
    {
        // Day 20: driven 1,000,000, overall 50,000 m/day, projected 1,000,000 + 50,000 x 345 = 18,250,000.
        // Remaining 35,500,000 over 345 days: day 102,898.6 -> 102,899; week 720,289.9 -> 720,290;
        // month 35,500,000 x 365 / (12 x 345) = 3,129,830.9 -> 3,129,831.
        // Room per week: (18,250,000 - 36,500,000) x 7 / 345 = -370,289.9 -> -370,290.
        $assessment = self::assess('2026-01-21 12:00', [['2026-01-21 00:00', 11_000_000]]);

        self::assertSame(RiskLevel::Ok, $assessment->riskLevel);
        self::assertSame(102_899, $assessment->budget->perDay->metres());
        self::assertSame(720_290, $assessment->budget->perWeek->metres());
        self::assertSame(3_129_831, $assessment->budget->perMonth->metres());
        self::assertSame(0, $assessment->projectedExcess->metres());
        self::assertSame(0, $assessment->projectedExcessCost->minorAmount());
        self::assertNotNull($assessment->weeklyCut);
        self::assertSame(-370_290, $assessment->weeklyCut->metres());
        self::assertTrue($assessment->weeklyCut->isNegative());
    }

    /**
     * @return iterable<string, array{int, int, RiskLevel}>
     */
    public static function boundaries(): iterable
    {
        // Day 20, driven D, projected D + D / 20 x 345 = 18.25 x D. D = 2,000,000 gives exactly 36,500,000 = A.
        yield 'exactly the allowance' => [0, 12_000_000, RiskLevel::Ok];
        // D = 2,000,001: overall 100,000.05, projected 2,000,001 + 34,500,017.25 = 36,500,018.25 -> 36,500,018 > A.
        yield 'one metre over on the day' => [0, 12_000_001, RiskLevel::Over];
        // Tolerance 5% (500 bp): Watch above A x 0.95 = 34,675,000, i.e. D above 1,900,000 (projected 34,675,000).
        yield 'exactly the tolerance limit' => [500, 11_900_000, RiskLevel::Ok];
        yield 'just above the tolerance limit' => [500, 11_900_001, RiskLevel::Watch];
        yield 'watch up to the allowance' => [500, 12_000_000, RiskLevel::Watch];
        yield 'over with a tolerance' => [500, 12_000_001, RiskLevel::Over];
        yield 'no tolerance: nothing to watch' => [0, 11_900_001, RiskLevel::Ok];
    }

    #[DataProvider('boundaries')]
    public function testRiskLevelBoundaries(int $toleranceBasisPoints, int $odometer, RiskLevel $expected): void
    {
        $assessment = self::assess('2026-01-21 12:00', [['2026-01-21 00:00', $odometer]], $toleranceBasisPoints);

        self::assertSame($expected, $assessment->riskLevel);
    }

    public function testTheAllowanceIsUsedUp(): void
    {
        // Day 100, driven 37,000,000 > A. Overall = recent = 370,000 m/day.
        // Projected 37,000,000 + 370,000 x 265 = 135,050,000, excess 98,550,000 m = 98,550 km x 0.45 = 44,347.50 CHF.
        // No budget left. To cut per week: 98,550,000 x 7 / 265 = 2,603,207.5 -> 2,603,208.
        $assessment = self::assess('2026-04-11 12:00', [['2026-04-11 00:00', 47_000_000]]);

        self::assertSame(RiskLevel::Over, $assessment->riskLevel);
        self::assertSame(0, $assessment->remaining->metres());
        self::assertSame(0, $assessment->budget->perDay->metres());
        self::assertSame(0, $assessment->budget->perWeek->metres());
        self::assertSame(0, $assessment->budget->perMonth->metres());
        self::assertSame(98_550_000, $assessment->projectedExcess->metres());
        self::assertSame(4_434_750, $assessment->projectedExcessCost->minorAmount());
        self::assertNotNull($assessment->weeklyCut);
        self::assertSame(2_603_208, $assessment->weeklyCut->metres());
    }

    public function testAnEndedLeaseKeepsAddingToTheExcess(): void
    {
        // Day 375, clock after the end: driven 37,500,000, projected = driven, excess 1,000,000 m = 1,000 km
        // x 0.45 = 450.00 CHF. Nothing left to drive: no budget, no weekly figure.
        $assessment = self::assess('2027-03-01 00:00', [['2027-01-11 00:00', 47_500_000]]);

        self::assertSame(LeaseStatus::Ended, $assessment->status);
        self::assertSame(RiskLevel::Over, $assessment->riskLevel);
        self::assertSame(1_000_000, $assessment->projectedExcess->metres());
        self::assertSame(45_000, $assessment->projectedExcessCost->minorAmount());
        self::assertSame(0, $assessment->budget->perDay->metres());
        self::assertNull($assessment->weeklyCut);

        // The car drives on 500 km: the excess grows.
        $later = self::assess('2027-03-01 00:00', [['2027-01-11 00:00', 47_500_000], ['2027-02-11 00:00', 48_000_000]]);

        self::assertSame(1_500_000, $later->projectedExcess->metres());
        self::assertSame(67_500, $later->projectedExcessCost->minorAmount());
    }

    public function testAnEndedLeaseUnderTheAllowanceCostsNothing(): void
    {
        // Driven 36,000,000 at day 375: 500,000 m of allowance unused, no cost, no budget.
        $assessment = self::assess('2027-03-01 00:00', [['2027-01-11 00:00', 46_000_000]]);

        self::assertSame(LeaseStatus::Ended, $assessment->status);
        self::assertSame(RiskLevel::Ok, $assessment->riskLevel);
        self::assertSame(500_000, $assessment->remaining->metres());
        self::assertSame(0, $assessment->budget->perDay->metres());
        self::assertSame(0, $assessment->projectedExcessCost->minorAmount());
    }

    public function testTheLeaseEndsWhenTheClockReachesItsExclusiveEnd(): void
    {
        $samples = [['2026-12-31 00:00', 46_000_000]];

        self::assertSame(LeaseStatus::Active, self::assess('2026-12-31 23:59:59', $samples)->status);
        self::assertSame(LeaseStatus::Ended, self::assess('2027-01-01 00:00:00', $samples)->status);
    }

    public function testAReturnedLeaseIgnoresTheSamplesAfterTheReturn(): void
    {
        // Returned on 2027-01-20 after the end: the sample of 2027-02-11 (48,000,000) is left out.
        // Driven 37,500,000, excess 1,000,000 m = 1,000 km x 0.45 = 450.00 CHF, nothing to forecast or budget.
        $assessment = self::assess('2027-03-01 00:00', [['2027-01-11 00:00', 47_500_000], ['2027-02-11 00:00', 48_000_000]], returnedAt: '2027-01-20 00:00');

        self::assertSame(LeaseStatus::Returned, $assessment->status);
        self::assertSame(RiskLevel::Over, $assessment->riskLevel);
        self::assertSame('2027-01-11T00:00:00+00:00', $assessment->projection->asOf->toIso8601String());
        self::assertSame(37_500_000, $assessment->projection->driven->metres());
        self::assertSame(37_500_000, $assessment->projection->projectedAtEnd->metres());
        self::assertSame(1_000_000, $assessment->projectedExcess->metres());
        self::assertSame(45_000, $assessment->projectedExcessCost->minorAmount());
        self::assertSame(0, $assessment->budget->perDay->metres());
        self::assertSame(0, $assessment->currentPace->perDay->metres());
        self::assertNull($assessment->weeklyCut);
    }

    public function testASampleExactlyAtTheReturnCounts(): void
    {
        $assessment = self::assess('2027-03-01 00:00', [['2027-01-11 00:00', 47_500_000], ['2027-01-20 00:00', 47_800_000]], returnedAt: '2027-01-20 00:00');

        self::assertSame(37_800_000, $assessment->projection->driven->metres());
        self::assertSame(1_300_000, $assessment->projectedExcess->metres());
    }

    public function testAnEarlyReturnIsMeasuredAgainstTheWholeAllowance(): void
    {
        // Returned on day 101, 264 days before the end: no forecast, the 9,000 km driven are far below the
        // 36,500 km allowance, so there is no excess whatever the pace was.
        $assessment = self::assess('2026-04-11 12:00', [['2026-03-12 00:00', 15_400_000], ['2026-04-11 00:00', 19_000_000]], returnedAt: '2026-04-12 00:00');

        self::assertSame(LeaseStatus::Returned, $assessment->status);
        self::assertSame(RiskLevel::Ok, $assessment->riskLevel);
        self::assertSame(9_000_000, $assessment->projection->projectedAtEnd->metres());
        self::assertSame(0, $assessment->projectedExcess->metres());
        self::assertSame(0, $assessment->projectedExcessCost->minorAmount());
        self::assertSame(27_500_000, $assessment->remaining->metres());
        self::assertSame(0, $assessment->budget->perWeek->metres());
        self::assertNull($assessment->weeklyCut);
    }

    public function testAnEarlyReturnOverTheWholeAllowanceCostsTheExcess(): void
    {
        // 40,000 km driven by day 200, returned then: 3,500 km over the whole allowance, not a prorated one.
        $assessment = self::assess('2026-07-30 00:00', [['2026-07-19 00:00', 50_000_000]], returnedAt: '2026-07-20 00:00');

        self::assertSame(RiskLevel::Over, $assessment->riskLevel);
        self::assertSame(3_500_000, $assessment->projectedExcess->metres());
        self::assertSame(157_500, $assessment->projectedExcessCost->minorAmount());
    }

    public function testAReturnedLeaseStaysReturnedWhateverTheClockSays(): void
    {
        $samples = [['2026-04-11 00:00', 19_000_000]];

        foreach (['2026-04-12 00:00', '2026-12-31 23:59:59', '2027-01-01 00:00', '2035-01-01 00:00'] as $now) {
            self::assertSame(LeaseStatus::Returned, self::assess($now, $samples, returnedAt: '2026-04-12 00:00')->status, $now);
        }
    }

    public function testUndoingTheReturnRestoresTheProjection(): void
    {
        $samples = [['2026-03-12 00:00', 15_400_000], ['2026-04-11 00:00', 19_000_000]];

        $active = self::assess('2026-04-11 12:00', $samples);
        $returned = self::assess('2026-04-11 12:00', $samples, returnedAt: '2026-04-12 00:00');

        self::assertSame(LeaseStatus::Active, $active->status);
        self::assertSame(1_120_000, $active->projectedExcess->metres());
        self::assertSame(0, $returned->projectedExcess->metres());
        self::assertNotNull($active->weeklyCut);
    }

    public function testAReturnBeforeAnyReadingGivesNoData(): void
    {
        self::assertInstanceOf(NoData::class, self::assessOrNoData('2027-03-01 00:00', [['2026-04-11 00:00', 19_000_000]], returnedAt: '2026-03-01 00:00'));
    }

    public function testNoSampleGivesNoData(): void
    {
        self::assertInstanceOf(NoData::class, self::assessOrNoData('2026-04-11 12:00', []));
    }

    public function testALeaseNotStartedYetGivesNoData(): void
    {
        self::assertInstanceOf(NoData::class, self::assessOrNoData('2025-12-15 12:00', [['2025-12-14 00:00', 9_990_000]]));
    }

    /**
     * @param list<array{string, int}> $samples
     */
    private static function assess(string $now, array $samples, int $toleranceBasisPoints = 0, ?string $returnedAt = null): LeaseAssessment
    {
        $assessment = self::assessOrNoData($now, $samples, $toleranceBasisPoints, $returnedAt);
        self::assertInstanceOf(LeaseAssessment::class, $assessment);

        return $assessment;
    }

    /**
     * @param list<array{string, int}> $samples
     */
    private static function assessOrNoData(string $now, array $samples, int $toleranceBasisPoints = 0, ?string $returnedAt = null): LeaseAssessment|NoData
    {
        $clock = new MockClock(new \DateTimeImmutable($now, new \DateTimeZone('UTC')));

        return new LeaseAssessor(new ProjectionCalculator($clock, RecentPaceWeight::fromBasisPoints(6_000)), $clock)->assess(
            new LeaseTerm(new CarbonImmutable('2026-01-01', 'UTC'), new CarbonImmutable('2027-01-01', 'UTC'), EndDateConvention::Exclusive),
            Allowance::ofTotal(Distance::fromKilometres(36_500)),
            Distance::fromKilometres(10_000),
            Money::fromDecimal('0.45', Currency::CHF),
            Tolerance::fromBasisPoints($toleranceBasisPoints),
            array_map(
                static fn (array $sample): OdometerSample => new OdometerSample(new CarbonImmutable($sample[0], 'UTC'), Distance::fromMetres($sample[1])),
                $samples,
            ),
            null === $returnedAt ? null : new CarbonImmutable($returnedAt, 'UTC'),
        );
    }
}
