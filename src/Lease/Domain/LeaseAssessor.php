<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

use Carbon\CarbonImmutable;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\DistanceDelta;
use Polaris\Shared\Domain\Money;
use Polaris\Shared\Domain\OdometerSample;
use Psr\Clock\ClockInterface;

/**
 * Turns a projection into budgets, a risk level and the estimated excess cost.
 *
 * Everything is measured from the latest reading, not from the clock's now. The lease has
 * ended when the clock's now reaches its exclusive end; from then on the car may keep
 * driving, and the excess keeps growing with the odometer, until the user marks the car as
 * returned.
 *
 * A returned lease is final whatever the clock says: readings after the return instant are
 * ignored, the figures are those of the last reading before or at it, the projected end is the
 * distance driven (no forecast), and the excess is that distance beyond the whole allowance,
 * also when the car went back before the lease end. Budgets and weekly cut are none.
 */
final readonly class LeaseAssessor
{
    public function __construct(
        private ProjectionCalculator $calculator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<OdometerSample> $samples
     * @param CarbonImmutable|null $returnedAt the instant the car went back (inclusive); null while the lease runs
     * @param CarbonImmutable|null $at         the instant the lease is judged at (has it ended, how long is left); null
     *                                         is the clock. The caller passes only samples up to it
     */
    public function assess(
        LeaseTerm $term,
        Allowance $allowance,
        Distance $startOdometer,
        Money $excessCostPerKm,
        Tolerance $tolerance,
        array $samples,
        ?CarbonImmutable $returnedAt = null,
        ?CarbonImmutable $at = null,
    ): LeaseAssessment|NoData {
        $returned = null !== $returnedAt;

        if (null !== $returnedAt) {
            $samples = self::samplesUpTo($samples, $returnedAt);
        }

        $projection = $this->calculator->calculate($term, $allowance, $startOdometer, $samples);

        if (null === $projection) {
            return new NoData();
        }

        if ($returned) {
            $projection = self::final($projection);
        }

        $allowed = $allowance->total()->metres();
        $projected = $projection->projectedAtEnd->metres();
        $now = null !== $at ? $at->getTimestamp() : $this->clock->now()->getTimestamp();
        $ended = $returned || $now >= $term->exclusiveEnd()->getTimestamp();
        $secondsLeft = $ended ? 0 : $term->exclusiveEnd()->getTimestamp() - $projection->asOf->getTimestamp();

        $remaining = Distance::fromMetres(max(0, $allowed - $projection->driven->metres()));
        $excess = Distance::fromMetres(max(0, $projected - $allowed));

        return new LeaseAssessment(
            status: match (true) {
                $returned => LeaseStatus::Returned,
                $ended => LeaseStatus::Ended,
                default => LeaseStatus::Active,
            },
            projection: $projection,
            riskLevel: self::riskLevel($projected, $allowed, $tolerance),
            remaining: $remaining,
            budget: Budget::spread($remaining, $term, $secondsLeft),
            projectedExcess: $excess,
            projectedExcessCost: ExcessCost::of($excess, $excessCostPerKm),
            weeklyCut: $secondsLeft > 0 ? self::weeklyCut($projected - $allowed, $secondsLeft) : null,
            currentPace: Budget::spread(
                Distance::fromMetres(max(0, $projected - $projection->driven->metres())),
                $term,
                $secondsLeft,
            ),
        );
    }

    /**
     * @param list<OdometerSample> $samples
     *
     * @return list<OdometerSample> the samples at or before $instant
     */
    private static function samplesUpTo(array $samples, CarbonImmutable $instant): array
    {
        $kept = [];

        foreach ($samples as $sample) {
            if ($sample->readAt()->getTimestamp() <= $instant->getTimestamp()) {
                $kept[] = $sample;
            }
        }

        return $kept;
    }

    /**
     * The same figures with nothing left to forecast: the projected end is the distance driven.
     */
    private static function final(Projection $projection): Projection
    {
        return new Projection(
            $projection->asOf,
            $projection->driven,
            $projection->allowedToDate,
            $projection->margin,
            $projection->overallPace,
            $projection->recentPace,
            $projection->blendedPace,
            $projection->driven,
        );
    }

    private static function riskLevel(int $projected, int $allowed, Tolerance $tolerance): RiskLevel
    {
        if ($projected > $allowed) {
            return RiskLevel::Over;
        }

        // projected > allowed x (1 - tolerance), in integers.
        $watchFrom = $allowed * (Tolerance::BASIS_POINTS_IN_ONE - $tolerance->basisPoints());

        if ($projected * Tolerance::BASIS_POINTS_IN_ONE > $watchFrom) {
            return RiskLevel::Watch;
        }

        return RiskLevel::Ok;
    }

    private static function weeklyCut(int $overAllowance, int $secondsLeft): DistanceDelta
    {
        $perWeek = Distance::fromMetres(HalfUp::scale(abs($overAllowance), Budget::SECONDS_PER_WEEK, $secondsLeft));
        $zero = Distance::fromMetres(0);

        return $overAllowance >= 0 ? DistanceDelta::between($zero, $perWeek) : DistanceDelta::between($perWeek, $zero);
    }
}
