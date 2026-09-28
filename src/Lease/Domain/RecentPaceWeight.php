<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

/**
 * The weight of the last-30-days pace in the blended pace, in basis points
 * (6000 = 0.6), from 0 to 10000. The overall pace takes the rest.
 */
final readonly class RecentPaceWeight
{
    public const int MAX_BASIS_POINTS = 10_000;

    private function __construct(
        private int $basisPoints,
    ) {
        if ($basisPoints < 0 || $basisPoints > self::MAX_BASIS_POINTS) {
            throw new \InvalidArgumentException(\sprintf('A recent pace weight must be between 0 and %d basis points, got %d.', self::MAX_BASIS_POINTS, $basisPoints));
        }
    }

    public static function fromBasisPoints(int $basisPoints): self
    {
        return new self($basisPoints);
    }

    public function basisPoints(): int
    {
        return $this->basisPoints;
    }

    /**
     * weight x recent + (1 - weight) x overall.
     */
    public function blend(Pace $recent, Pace $overall): Pace
    {
        return Pace::fromMetresPerDay(
            ($this->basisPoints * $recent->metresPerDay() + (self::MAX_BASIS_POINTS - $this->basisPoints) * $overall->metresPerDay())
            / self::MAX_BASIS_POINTS,
        );
    }
}
