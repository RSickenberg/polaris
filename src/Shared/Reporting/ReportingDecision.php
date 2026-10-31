<?php

declare(strict_types=1);

namespace Polaris\Shared\Reporting;

/**
 * Whether what the SDK is about to send may leave, and with what.
 */
final readonly class ReportingDecision
{
    private function __construct(
        public bool $allowed,
        /** The ULID of the user to attach to the report, null when it belongs to no user. */
        public ?string $userId,
        /** Whether vehicle data may stay in the report. */
        public bool $details,
    ) {
    }

    public static function drop(): self
    {
        return new self(false, null, false);
    }

    public static function instanceOnly(): self
    {
        return new self(true, null, false);
    }

    public static function forUser(string $userId, bool $details): self
    {
        return new self(true, $userId, $details);
    }
}
