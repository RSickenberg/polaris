<?php

declare(strict_types=1);

namespace Polaris\Fetching\Domain;

use Carbon\CarbonImmutable;

/**
 * How many wakes a vehicle has left. Not an entity: it is computed from the wakes of the last 24 hours.
 */
final readonly class WakeCapStatus
{
    /**
     * @param CarbonImmutable|null $availableAt when the next wake is allowed, null when one is allowed now or when the cap is 0 (never)
     */
    public function __construct(
        public int $cap,
        public int $used,
        public ?CarbonImmutable $availableAt,
    ) {
    }

    public function isReached(): bool
    {
        return $this->used >= $this->cap;
    }
}
