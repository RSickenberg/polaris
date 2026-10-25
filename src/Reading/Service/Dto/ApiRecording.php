<?php

declare(strict_types=1);

namespace Polaris\Reading\Service\Dto;

use Polaris\Reading\Domain\ReadingRejection;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Reading\Service\Enum\RecordOutcome;

/**
 * The result of recording a value read from the car: the outcome, the stored reading when there is one,
 * and the neighbouring reading and the rule that refused the value when it was rejected.
 */
final readonly class ApiRecording
{
    private function __construct(
        public RecordOutcome $outcome,
        public ?OdometerReading $stored,
        public ?OdometerReading $conflicting,
        public ?ReadingRejection $rejection,
    ) {
    }

    public static function stored(OdometerReading $reading): self
    {
        return new self(RecordOutcome::Stored, $reading, null, null);
    }

    public static function unchanged(): self
    {
        return new self(RecordOutcome::Unchanged, null, null, null);
    }

    public static function rejected(ReadingRejection $rejection, OdometerReading $conflicting): self
    {
        return new self(RecordOutcome::Rejected, null, $conflicting, $rejection);
    }
}
