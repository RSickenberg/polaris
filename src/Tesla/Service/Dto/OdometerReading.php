<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Dto;

use Carbon\CarbonImmutable;
use Polaris\Shared\Domain\Distance;

/**
 * An odometer read live from the car, at the time the car reported it (`vehicle_state.timestamp`, epoch
 * milliseconds), or at the clock when the answer has none. Always UTC.
 */
final readonly class OdometerReading
{
    public function __construct(
        public Distance $odometer,
        public CarbonImmutable $readAt,
    ) {
    }
}
