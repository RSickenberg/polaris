<?php

declare(strict_types=1);

namespace Polaris\Fetching\Service\Dto;

use Carbon\CarbonImmutable;
use Polaris\Fetching\Service\Enum\WakeRequestStatus;

final readonly class WakeRequestResult
{
    private function __construct(
        public WakeRequestStatus $status,
        public ?CarbonImmutable $availableAt = null,
    ) {
    }

    public static function accepted(): self
    {
        return new self(WakeRequestStatus::Accepted);
    }

    /**
     * @param CarbonImmutable|null $availableAt when the next wake is allowed, null when waking is turned off
     */
    public static function capReached(?CarbonImmutable $availableAt): self
    {
        return new self(WakeRequestStatus::CapReached, $availableAt);
    }

    /**
     * @param CarbonImmutable $availableAt when the hourly limit of the refresh frees up
     */
    public static function rateLimited(CarbonImmutable $availableAt): self
    {
        return new self(WakeRequestStatus::RateLimited, $availableAt);
    }

    public static function refused(WakeRequestStatus $status): self
    {
        return new self($status);
    }

    public function isAccepted(): bool
    {
        return WakeRequestStatus::Accepted === $this->status;
    }
}
