<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Domain;

use Carbon\CarbonImmutable;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\OdometerSample;

/**
 * Builds the curves of the mileage chart.
 *
 * The rules for the readings are those of the projection: readings at or before the lease
 * start are ignored and readings past the lease end are kept. Every distance is measured
 * from the start odometer, so the curves compare directly with the allowance.
 */
final readonly class MileageSeriesBuilder
{
    /**
     * @param CarbonImmutable      $start         the lease start instant
     * @param CarbonImmutable      $end           the lease end instant the projection runs to
     * @param Distance             $startOdometer the odometer at the lease start
     * @param Distance             $allowance     the whole allowance
     * @param list<OdometerSample> $samples       in any order
     * @param MileageForecast|null $forecast      null draws no projection
     *
     * @throws \InvalidArgumentException when a reading is below the start odometer
     */
    public function build(
        CarbonImmutable $start,
        CarbonImmutable $end,
        Distance $startOdometer,
        Distance $allowance,
        array $samples,
        ?MileageForecast $forecast,
    ): MileageSeries {
        return new MileageSeries(
            $this->actual($start, $startOdometer, $samples),
            [
                new MileagePoint($start, Distance::fromMetres(0)),
                new MileagePoint($end, $allowance),
            ],
            null === $forecast
                ? []
                : [
                    new MileagePoint($forecast->asOf, $forecast->driven),
                    new MileagePoint($end, $forecast->projectedAtEnd),
                ],
        );
    }

    /**
     * @param list<OdometerSample> $samples
     *
     * @return non-empty-list<MileagePoint>
     */
    private function actual(CarbonImmutable $start, Distance $startOdometer, array $samples): array
    {
        $driven = [];

        foreach ($samples as $sample) {
            $at = $sample->readAt();
            if ($at->getTimestamp() <= $start->getTimestamp()) {
                continue;
            }

            $metres = $sample->odometer()->metres() - $startOdometer->metres();
            if ($metres < 0) {
                throw new \InvalidArgumentException(\sprintf('The odometer of %s is below the start odometer.', $at->toIso8601String()));
            }

            // Two readings of the same second keep the later one given; the projection refuses a disagreement before this.
            $driven[$at->getTimestamp()] = new MileagePoint($at, Distance::fromMetres($metres));
        }

        ksort($driven);

        return [
            new MileagePoint($start, Distance::fromMetres(0)),
            ...array_values($driven),
        ];
    }
}
