<?php

declare(strict_types=1);

namespace Polaris\Reading\Service\Dto;

use Carbon\CarbonImmutable;
use Polaris\Reading\Domain\ReadingSource;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\DistanceUnit;

/**
 * A reading to bring back as it was exported: its instant, its odometer and where it came from.
 */
final readonly class ReadingImport
{
    public function __construct(
        public CarbonImmutable $readAt,
        public Distance $odometer,
        public ReadingSource $source,
        /** The odometer as the Tesla API returned it, for a reading of the API only. */
        public ?string $odometerRaw = null,
        public ?DistanceUnit $odometerRawUnit = null,
        /** The latest instant the car was seen with this odometer, when it did not move. */
        public ?CarbonImmutable $lastVerifiedAt = null,
    ) {
    }
}
