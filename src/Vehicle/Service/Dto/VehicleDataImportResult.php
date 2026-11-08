<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Service\Dto;

use Polaris\Lease\Service\Dto\SnapshotsImportResult;
use Polaris\Reading\Service\Dto\ReadingsImportResult;

/**
 * What importing an export did to a vehicle. The import only adds: nothing is replaced or deleted.
 */
final readonly class VehicleDataImportResult
{
    public function __construct(
        /** The file had a lease and the vehicle had none: it was created. */
        public bool $leaseCreated,
        /** The file had a lease but the vehicle already has one: the existing one was kept. */
        public bool $leaseKept,
        public ReadingsImportResult $readings,
        public SnapshotsImportResult $snapshots,
    ) {
    }
}
