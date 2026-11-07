<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Service\Dto;

use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\DistanceDelta;

/**
 * The figures of a row that has a result: what happened, what was expected, and how far apart they are.
 */
final readonly class ComparisonFigures
{
    public function __construct(
        public Distance $actual,
        public Distance $expected,
        /** Positive when more than expected. */
        public DistanceDelta $delta,
        /** The delta as a share of the expected distance (0.1 is 10 %); null when nothing was expected. */
        public ?float $ratio,
    ) {
    }
}
