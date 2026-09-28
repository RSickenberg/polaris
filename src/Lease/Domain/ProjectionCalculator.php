<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

use Carbon\CarbonImmutable;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\DistanceDelta;
use Polaris\Shared\Domain\OdometerSample;
use Psr\Clock\ClockInterface;

/**
 * Projects the distance driven at lease end from the odometer samples.
 *
 * Time is measured in exact seconds between UTC instants, and paces are given per day
 * of 86,400 seconds. The lease start is the first data point, at the contract's start
 * odometer, and the odometer is assumed linear between two samples.
 *
 * - The reference instant is the latest sample, capped at the lease end: after it, the
 *   odometer at the end is interpolated from the samples around it.
 * - Allowed to date = allowance x (reference - start) / (end - start), computed in
 *   integers and rounded half up.
 * - Overall pace = driven / (reference - start).
 * - Recent pace = odometer change over the 30 days before the reference instant,
 *   interpolated at the window start; null when the lease is younger than 30 days.
 * - Blended pace = weight x recent + (1 - weight) x overall, or overall alone.
 * - Projected at end = driven + blended pace x (end - reference), rounded half up once.
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
    public function calculate(LeaseTerm $term, Allowance $allowance, Distance $startOdometer, array $samples): ?Projection
    {
        $start = $term->start()->getTimestamp();
        $end = $term->exclusiveEnd()->getTimestamp();
        $termSeconds = $end - $start;
        $allowanceMetres = $allowance->total()->metres();

        if ($allowanceMetres > intdiv(\PHP_INT_MAX - $termSeconds, 2 * $termSeconds)) {
            throw new \InvalidArgumentException('The allowance is too large to project.');
        }

        $timeline = $this->timeline($start, $startOdometer, $samples);

        if (1 === \count($timeline)) {
            return null;
        }

        $latest = array_key_last($timeline);
        $reference = $latest < $end ? $latest : $end;
        $elapsed = $reference - $start;
        $referenceOdometer = self::odometerAt($timeline, $reference);
        $drivenMetres = $referenceOdometer - $startOdometer->metres();

        $overallPace = Pace::over($drivenMetres, $elapsed);
        $recentPace = null;
        $blendedPace = $overallPace;
        $windowSeconds = self::RECENT_WINDOW_DAYS * Pace::SECONDS_PER_DAY;

        if ($elapsed >= $windowSeconds) {
            $recentPace = Pace::over($referenceOdometer - self::odometerAt($timeline, $reference - $windowSeconds), $windowSeconds);
            $blendedPace = $this->recentPaceWeight->blend($recentPace, $overallPace);
        }

        $driven = Distance::fromMetres((int) round($drivenMetres));
        // round(allowance x elapsed / term), half up, in integers: floor((2 x allowance x elapsed + term) / (2 x term)).
        $allowedToDate = Distance::fromMetres(intdiv(2 * $allowanceMetres * $elapsed + $termSeconds, 2 * $termSeconds));

        return new Projection(
            asOf: CarbonImmutable::createFromTimestamp($reference, 'UTC'),
            driven: $driven,
            allowedToDate: $allowedToDate,
            margin: DistanceDelta::between($driven, $allowedToDate),
            overallPace: $overallPace,
            recentPace: $recentPace,
            blendedPace: $blendedPace,
            projectedAtEnd: Distance::fromMetres((int) round($drivenMetres + $blendedPace->metresIn($end - $reference))),
        );
    }

    /**
     * The odometer in metres by Unix timestamp, sorted, starting with the lease start.
     *
     * @param list<OdometerSample> $samples
     *
     * @return non-empty-array<int, int>
     */
    private function timeline(int $start, Distance $startOdometer, array $samples): array
    {
        $now = $this->clock->now()->getTimestamp();
        $odometers = [];

        foreach ($samples as $sample) {
            $readAt = $sample->readAt()->getTimestamp();
            $odometer = $sample->odometer()->metres();

            if ($readAt > $now) {
                throw new \InvalidArgumentException(\sprintf('The odometer sample of %s is in the future.', $sample->readAt()->toIso8601String()));
            }

            if ($readAt <= $start) {
                continue;
            }

            if (isset($odometers[$readAt]) && $odometers[$readAt] !== $odometer) {
                throw new \InvalidArgumentException(\sprintf('Two odometer samples of %s disagree: %d m and %d m.', $sample->readAt()->toIso8601String(), $odometers[$readAt], $odometer));
            }

            $odometers[$readAt] = $odometer;
        }

        ksort($odometers);
        $timeline = [$start => $startOdometer->metres()] + $odometers;
        $previous = $startOdometer->metres();

        foreach ($timeline as $readAt => $odometer) {
            if ($odometer < $previous) {
                throw new \InvalidArgumentException(\sprintf('The odometer of %s (%d m) is lower than an earlier one (%d m).', CarbonImmutable::createFromTimestamp($readAt, 'UTC')->toIso8601String(), $odometer, $previous));
            }

            $previous = $odometer;
        }

        return $timeline;
    }

    /**
     * The odometer at $instant, interpolated linearly between the samples around it.
     *
     * @param non-empty-array<int, int> $timeline sorted, with $instant between its first and last keys
     */
    private static function odometerAt(array $timeline, int $instant): float
    {
        $before = array_key_last(array_filter($timeline, static fn (int $readAt): bool => $readAt <= $instant, \ARRAY_FILTER_USE_KEY));
        $after = array_key_first(array_filter($timeline, static fn (int $readAt): bool => $readAt >= $instant, \ARRAY_FILTER_USE_KEY));
        \assert(null !== $before && null !== $after);

        if ($before === $after) {
            return $timeline[$instant];
        }

        return $timeline[$before] + ($timeline[$after] - $timeline[$before]) * (($instant - $before) / ($after - $before));
    }
}
