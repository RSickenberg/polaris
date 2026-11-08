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
        public Distance $actual,
        public Distance $expected,
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
}
