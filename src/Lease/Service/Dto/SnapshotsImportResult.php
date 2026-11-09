<?php

declare(strict_types=1);

namespace Polaris\Lease\Service\Dto;

/**
 * What an import of projection snapshots did. A snapshot is a record: nothing is replaced or deleted.
 */
final readonly class SnapshotsImportResult
{
    public function __construct(
        public int $added,
        /** Snapshots of a local day that already has one. */
        public int $alreadyThere,
        /**
         * Snapshots that cannot be kept: without a cutoff or a pace (saved before they were stored), or without a
         * lease.
         */
        public int $skipped,
    ) {
    }
}
