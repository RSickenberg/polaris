<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Dto;

use Carbon\CarbonImmutable;
use Polaris\Shared\Domain\Distance;

/**
 * An odometer read live from the car. The read time is the clock at the moment of the read (UTC):
 * the timestamp of a live answer is not read, since only the cached answers were seen.
 */
final readonly class OdometerReading
{
    public function __construct(
        public Distance $odometer,
        public CarbonImmutable $readAt,
    ) {
    }
}
