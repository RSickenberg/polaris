<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

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
 * driving, and the excess keeps growing with the odometer.
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
     */
    public function assess(LeaseTerm $term, Allowance $allowance, Distance $startOdometer, Money $excessCostPerKm, Tolerance $tolerance, array $samples): LeaseAssessment|NoData
    {
        $projection = $this->calculator->calculate($term, $allowance, $startOdometer, $samples);

        if (null === $projection) {
            return new NoData();
        }

        $allowed = $allowance->total()->metres();
        $projected = $projection->projectedAtEnd->metres();
        $ended = $this->clock->now()->getTimestamp() >= $term->exclusiveEnd()->getTimestamp();
        $secondsLeft = $ended ? 0 : $term->exclusiveEnd()->getTimestamp() - $projection->asOf->getTimestamp();

        $remaining = Distance::fromMetres(max(0, $allowed - $projection->driven->metres()));
        $excess = Distance::fromMetres(max(0, $projected - $allowed));

        return new LeaseAssessment(
            status: $ended ? LeaseStatus::Ended : LeaseStatus::Active,
            projection: $projection,
            riskLevel: self::riskLevel($projected, $allowed, $tolerance),
            remaining: $remaining,
            budget: Budget::spread($remaining, $term, $secondsLeft),
            projectedExcess: $excess,
            projectedExcessCost: ExcessCost::of($excess, $excessCostPerKm),
            weeklyCut: $secondsLeft > 0 ? self::weeklyCut($projected - $allowed, $secondsLeft) : null,
        );
    }

    private static function riskLevel(int $projected, int $allowed, Tolerance $tolerance): RiskLevel
    {
        if ($projected > $allowed) {
            return RiskLevel::Over;
        }

        // projected > allowed x (1 - tolerance), in integers.
        if ($projected * Tolerance::BASIS_POINTS_IN_ONE > $allowed * (Tolerance::BASIS_POINTS_IN_ONE - $tolerance->basisPoints())) {
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
