<?php

declare(strict_types=1);

namespace Polaris\Fetching\Message;

/**
 * Reads the odometer of one vehicle. Sent by the scheduler for a slot (and again an hour later while the
 * car is not online). Scalars only, so a message queued by an older version still deserializes: new
 * fields are added at the end with a default.
 */
final readonly class FetchOdometerMessage
{
    /**
     * @param string      $vehicleId     the ULID (RFC 4122 form) of the vehicle
     * @param int|null    $slotTimestamp the UTC instant of the scheduled slot, null for a fetch that belongs to no slot (a manual refresh)
     * @param int         $attempt       how many state checks already happened for this slot (0 for the first)
     * @param string|null $refreshId     the ULID (RFC 4122 form) of the {@see \Polaris\Fetching\Entity\RefreshAttempt} a manual refresh settles, null otherwise
     */
    public function __construct(
        public string $vehicleId,
        public ?int $slotTimestamp = null,
        public int $attempt = 0,
        public ?string $refreshId = null,
    ) {
    }

    public function nextAttempt(): self
    {
        return new self($this->vehicleId, $this->slotTimestamp, $this->attempt + 1, $this->refreshId);
    }
}
