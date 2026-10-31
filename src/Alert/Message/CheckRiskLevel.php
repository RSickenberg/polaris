<?php

declare(strict_types=1);

namespace Polaris\Alert\Message;

/**
 * The risk level of a vehicle may have changed: a reading was stored or confirmed, or the lease terms were saved.
 */
final readonly class CheckRiskLevel
{
    public function __construct(
        public string $vehicleId,
        /** How many times the check found another one running and was queued again. */
        public int $attempt = 0,
    ) {
    }
}
