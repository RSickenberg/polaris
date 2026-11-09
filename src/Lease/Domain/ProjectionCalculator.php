<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

use Carbon\CarbonImmutable;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\DistanceDelta;
use Polaris\Shared\Domain\OdometerSample;
use Polaris\Shared\Domain\TimeZone;
use Psr\Clock\ClockInterface;

/**
 * Projects the distance driven at lease end from the odometer samples.
 *
 * Time is measured in exact seconds between UTC instants, and paces are given per day
 * of 86,400 seconds. The lease start is the first data point, at the contract's start
 * odometer, and the odometer is assumed linear between two samples.
 *
 * - The reference instant is the latest sample, even after the lease end: a car that keeps
 *   driving past the end keeps adding to the distance driven.
 * - Allowed to date = allowance x (reference - start) / (end - start), computed in
 *   integers and rounded half up. It stops at the whole allowance once the term is over.
 * - Overall pace = driven / (reference - start).
 * - Recent pace = odometer change over the 30 days before the reference instant,
 *   interpolated at the window start; null when the lease is younger than 30 days.
 * - Blended pace = weight x recent + (1 - weight) x overall, or overall alone.
 * - Projected at end = driven + blended pace x (end - reference), rounded half up once;
 *   after the end nothing is left to drive, so it equals the distance driven.
 *
 * Samples may come in any order. Samples at or before the lease start are ignored. A
 * sample after the clock's now, an odometer lower than an earlier one (or than the start
 * odometer), and two different odometers at the same second are rejected.
 */
final readonly class ProjectionCalculator
{
    public const int RECENT_WINDOW_DAYS = 30;

    public function __construct(
        private ClockInterface $clock,
        private RecentPaceWeight $recentPaceWeight,
    ) {
    }

    /**
     * @param list<OdometerSample> $samples
     *
     * @return Projection|null null when no sample falls after the lease start yet
     */
    public function calculate(
        LeaseTerm $term,
        Allowance $allowance,
        Distance $startOdometer,
        array $samples
    ): ?Projection {
        $start = $term->start()->getTimestamp();
        $end = $term->exclusiveEnd()->getTimestamp();
        $termSeconds = $end - $start;
        $allowanceMetres = $allowance->total()->metres();

        if ($allowanceMetres > intdiv(\PHP_INT_MAX - $termSeconds, 2 * $termSeconds)) {
            throw new \InvalidArgumentException('The allowance is too large to project.');
        }

        $timeline = OdometerTimeline::fromSamples(
            $term->start(),
            $startOdometer,
            $samples,
            CarbonImmutable::instance($this->clock->now())
        );

        if (!$timeline->hasSamples()) {
            return null;
        }

        $reference = $timeline->latestInstant();
        $elapsed = $reference - $start;
        $referenceOdometer = $timeline->odometerAt($reference);
        $drivenMetres = $referenceOdometer - $startOdometer->metres();

        $overallPace = Pace::over($drivenMetres, $elapsed);
        $recentPace = null;
        $blendedPace = $overallPace;
        $windowSeconds = self::RECENT_WINDOW_DAYS * Pace::SECONDS_PER_DAY;

        if ($elapsed >= $windowSeconds) {
            $recentPace = Pace::over(
                $referenceOdometer - $timeline->odometerAt($reference - $windowSeconds),
                $windowSeconds
            );
            $blendedPace = $this->recentPaceWeight->blend($recentPace, $overallPace);
        }

        $driven = Distance::fromMetres((int) round($drivenMetres));
        // round(allowance x elapsed / term), half up, in integers:
        // floor((2 x allowance x elapsed + term) / (2 x term)).
        $allowedToDate = Distance::fromMetres(
            intdiv(2 * $allowanceMetres * min($elapsed, $termSeconds) + $termSeconds, 2 * $termSeconds)
        );

        return new Projection(
            asOf: CarbonImmutable::createFromTimestamp($reference, TimeZone::UTC),
            driven: $driven,
            allowedToDate: $allowedToDate,
            margin: DistanceDelta::between($driven, $allowedToDate),
            overallPace: $overallPace,
            recentPace: $recentPace,
            blendedPace: $blendedPace,
            projectedAtEnd: Distance::fromMetres(
                (int) round($drivenMetres + $blendedPace->metresIn(max(0, $end - $reference)))
            ),
        );
    }
}
