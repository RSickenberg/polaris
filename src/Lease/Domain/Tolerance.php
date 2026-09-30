<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

/**
 * The tolerance of a lease contract, in basis points (1% = 100 bp), from 0 to 50%.
 */
final readonly class Tolerance
{
    public const int MAX_BASIS_POINTS = 5000;
    public const int BASIS_POINTS_IN_ONE = 10_000;

    private function __construct(
        private int $basisPoints,
    ) {
        if ($basisPoints < 0 || $basisPoints > self::MAX_BASIS_POINTS) {
            throw new \InvalidArgumentException(\sprintf('A tolerance must be between 0 and %d basis points, got %d.', self::MAX_BASIS_POINTS, $basisPoints));
        }
    }

    public static function fromBasisPoints(int $basisPoints): self
    {
        return new self($basisPoints);
    }

    /**
     * Converts a percentage such as 2.5 to basis points, rounded half up.
     */
    public static function fromPercent(int|float $percent): self
    {
        if (!is_finite((float) $percent)) {
            throw new \InvalidArgumentException('A tolerance must be a finite number.');
        }

        $basisPoints = round($percent * 100);

        // Checked before the cast: PHP 8.5 deprecates casting an out-of-range float to int.
        if ($basisPoints < 0 || $basisPoints > self::MAX_BASIS_POINTS) {
            throw new \InvalidArgumentException(\sprintf('A tolerance must be between 0%% and %d%%, got %s%%.', self::MAX_BASIS_POINTS / 100, $percent));
        }

        return new self((int) $basisPoints);
    }

    public function basisPoints(): int
    {
        return $this->basisPoints;
    }

    public function percent(): float
    {
        return $this->basisPoints / 100;
    }
}
