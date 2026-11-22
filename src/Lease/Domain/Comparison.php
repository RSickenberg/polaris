<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\DistanceDelta;

/**
 * What was driven (or projected) compared with what a past prediction expected. A positive delta means more than
 * expected.
 */
final readonly class Comparison
{
    private function __construct(
        public Distance|DistanceDelta $actual,
        public Distance|DistanceDelta $expected,
        public DistanceDelta $delta,
        /** The delta as a share of the expected distance (0.1 is 10 %); null when nothing was expected. */
        public ?float $ratio,
    ) {
    }

    public static function of(Distance $actual, Distance $expected): self
    {
        $delta = DistanceDelta::between($expected, $actual);

        return new self(
            $actual,
            $expected,
            $delta,
            0 === $expected->metres() ? null : $delta->metres() / $expected->metres(),
        );
    }

    /**
     * A margin now against a margin then: both are signed, and the delta is how much the margin grew (positive) or
     * shrank. The share is taken over the size of the margin then, whatever its sign.
     */
    public static function ofMargins(DistanceDelta $now, DistanceDelta $then): self
    {
        $change = $now->metres() - $then->metres();

        return new self(
            $now,
            $then,
            DistanceDelta::fromMetres($change),
            0 === $then->metres() ? null : $change / abs($then->metres()),
        );
    }

    /**
     * The same comparison without the share of the expected distance (a window too short for it to mean anything).
     */
    public function withoutRatio(): self
    {
        return new self($this->actual, $this->expected, $this->delta, null);
    }
}
