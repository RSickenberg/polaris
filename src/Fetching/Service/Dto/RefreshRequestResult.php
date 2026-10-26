<?php

declare(strict_types=1);

namespace Polaris\Fetching\Service\Dto;

use Carbon\CarbonImmutable;
use Polaris\Fetching\Service\Enum\RefreshRequestStatus;
use Symfony\Component\Uid\Ulid;

final readonly class RefreshRequestResult
{
    private function __construct(
        public RefreshRequestStatus $status,
        public ?Ulid $attemptId,
        public ?CarbonImmutable $retryAfter,
    ) {
    }

    public static function accepted(Ulid $attemptId): self
    {
        return new self(RefreshRequestStatus::Accepted, $attemptId, null);
    }

    /**
     * @param CarbonImmutable $retryAfter the UTC instant from which the vehicle can be refreshed again
     */
    public static function rateLimited(CarbonImmutable $retryAfter): self
    {
        return new self(RefreshRequestStatus::RateLimited, null, $retryAfter);
    }

    public function isAccepted(): bool
    {
        return RefreshRequestStatus::Accepted === $this->status;
    }
}
