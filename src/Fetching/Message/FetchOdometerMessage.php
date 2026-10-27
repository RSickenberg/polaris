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
     * @param bool        $allowWake     whether a car found asleep may be woken: only a manual refresh the user confirmed sets it, a scheduled fetch never does
     * @param int|null    $wakeDeadline  the UTC instant after which a woken car that is still not online is given up on, null before wake_up was sent
     */
    public function __construct(
        public string $vehicleId,
        public ?int $slotTimestamp = null,
        public int $attempt = 0,
        public ?string $refreshId = null,
        public bool $allowWake = false,
        public ?int $wakeDeadline = null,
    ) {
    }

    public function nextAttempt(): self
    {
        return new self(
            $this->vehicleId,
            $this->slotTimestamp,
            $this->attempt + 1,
            $this->refreshId,
            $this->allowWake,
            $this->wakeDeadline
        );
    }

    /**
     * The same fetch once wake_up was sent: it now polls the state until the deadline.
     */
    public function wokenUntil(int $deadline): self
    {
        return new self(
            $this->vehicleId,
            $this->slotTimestamp,
            $this->attempt,
            $this->refreshId,
            $this->allowWake,
            $deadline
        );
    }

    /**
     * Whether this fetch may send wake_up: a confirmed manual refresh that has not woken the car yet. A scheduled
     * fetch (it has a slot) never does.
     */
    public function asksForWake(): bool
    {
        return $this->allowWake && !$this->isPollingWake() && null === $this->slotTimestamp;
    }

    public function isPollingWake(): bool
    {
        return null !== $this->wakeDeadline;
    }
}
