<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Dto;

use Polaris\Tesla\Service\Enum\RegistrationStatus;

/**
 * The outcome of registering the app. When $blocker is set, nothing was sent to Tesla.
 */
final readonly class RegistrationReport
{
    /**
     * @param list<RegionRegistrationResult> $results
     */
    private function __construct(
        public ?string $blocker,
        public ?PublicKeyCheckResult $keyCheck,
        public array $results,
    ) {
    }

    public static function blocked(string $blocker, ?PublicKeyCheckResult $keyCheck = null): self
    {
        return new self($blocker, $keyCheck, []);
    }

    /**
     * @param list<RegionRegistrationResult> $results
     */
    public static function completed(PublicKeyCheckResult $keyCheck, array $results): self
    {
        return new self(null, $keyCheck, $results);
    }

    public function isSuccess(): bool
    {
        if (null !== $this->blocker || [] === $this->results) {
            return false;
        }

        foreach ($this->results as $result) {
            if (RegistrationStatus::Registered !== $result->status) {
                return false;
            }
        }

        return true;
    }
}
