<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Service\Dto;

use Polaris\Lease\Service\Dto\SnapshotsImportResult;
use Polaris\Reading\Service\Dto\ReadingsImportResult;
use Polaris\Vehicle\Service\Enum\LeaseImportOutcome;

/**
 * What importing an export did to a vehicle. The import only adds: nothing is replaced or deleted.
 */
final readonly class VehicleDataImportResult
{
    public function __construct(
        public LeaseImportOutcome $lease,
        public ReadingsImportResult $readings,
        public SnapshotsImportResult $snapshots,
    ) {
    }
}
