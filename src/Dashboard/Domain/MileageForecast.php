<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Domain;

use Carbon\CarbonImmutable;
use Polaris\Shared\Domain\Distance;

/**
 * The figures of a lease projection that the chart draws, taken as they are from the
 * assessment: nothing is recomputed here.
 */
final readonly class MileageForecast
{
    public function __construct(
        /** The instant the projection starts at: the latest reading. */
        public CarbonImmutable $asOf,
        /** The distance driven at {@see $asOf}. */
        public Distance $driven,
        /** The distance driven at the lease end if the current pace holds. */
        public Distance $projectedAtEnd,
    ) {
    }
}
