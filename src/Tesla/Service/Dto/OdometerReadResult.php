<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Dto;

use Polaris\Tesla\Service\Enum\NoReadingReason;

/**
 * The outcome of reading one vehicle: a reading, or the reason there is none.
 */
final readonly class OdometerReadResult
{
    private function __construct(
        public ?OdometerReading $reading,
        public ?NoReadingReason $reason,
    ) {
    }

    public static function read(OdometerReading $reading): self
    {
        return new self($reading, null);
    }

    public static function none(NoReadingReason $reason): self
    {
        return new self(null, $reason);
    }

    public function hasReading(): bool
    {
        return null !== $this->reading;
    }
}
