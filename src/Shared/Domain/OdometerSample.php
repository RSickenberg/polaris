<?php

declare(strict_types=1);

namespace Polaris\Shared\Domain;

use Carbon\CarbonImmutable;

/**
 * An odometer value at an instant, as modules exchange it: the Reading module stores
 * them, and the Lease module projects the mileage from them.
 */
final readonly class OdometerSample
{
    public function __construct(
        private CarbonImmutable $readAt,
        private Distance $odometer,
    ) {
        if (0 !== $readAt->getOffset()) {
            throw new \InvalidArgumentException(\sprintf('An odometer sample must be read at a UTC instant, got %s.', $readAt->toIso8601String()));
        }
    }

    public function readAt(): CarbonImmutable
    {
        return $this->readAt;
    }

    public function odometer(): Distance
    {
        return $this->odometer;
    }
}
