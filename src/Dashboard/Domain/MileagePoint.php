<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Domain;

use Carbon\CarbonImmutable;
use Polaris\Shared\Domain\Distance;

/**
 * One point of a mileage curve: a UTC instant and a distance driven since the lease start.
 */
final readonly class MileagePoint
{
    public function __construct(
        public CarbonImmutable $at,
        public Distance $distance,
    ) {
    }
}
