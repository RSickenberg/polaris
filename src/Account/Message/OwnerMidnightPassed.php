<?php

declare(strict_types=1);

namespace Polaris\Account\Message;

use Polaris\Shared\Reporting\ReportsForUser;

/**
 * The local midnight M of an owner has passed: every module with daily work does it for this owner (the projection
 * snapshots of the Lease module, the purge of old manual refreshes of the Fetching module). Sent once per owner and
 * local day, whenever the job actually runs: the work is taken at M, not at the clock.
 */
final readonly class OwnerMidnightPassed implements ReportsForUser
{
    public function __construct(
        /** The ULID (RFC 4122 form) of the owner. */
        public string $userId,
        /** The calendar date M opens, as `Y-m-d` (a label of the owner's zone at the time). */
        public string $localDay,
        /** M as a Unix timestamp. */
        public int $midnightTimestamp,
    ) {
    }

    public function reportingUserId(): string
    {
        return $this->userId;
    }
}
