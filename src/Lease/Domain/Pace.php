<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

/**
 * A driving pace, in metres per day, kept at full float precision.
 *
 * A day is 86,400 seconds: every instant is in UTC, so days have no daylight saving
 * shift. Round only when turning a pace into a distance.
 */
final readonly class Pace
{
    public const int SECONDS_PER_DAY = 86_400;

    private function __construct(
        private float $metresPerDay,
    ) {
        if (!is_finite($metresPerDay)) {
            throw new \InvalidArgumentException('A pace must be a finite number.');
        }

        if ($metresPerDay < 0) {
            throw new \InvalidArgumentException(\sprintf('A pace cannot be negative, got %s m/day.', $metresPerDay));
        }
    }

    public static function fromMetresPerDay(float $metresPerDay): self
    {
        return new self($metresPerDay);
    }

    /**
     * The pace of driving $metres in $seconds.
     */
    public static function over(float $metres, int $seconds): self
    {
        if ($seconds <= 0) {
            throw new \InvalidArgumentException(\sprintf('A pace needs a positive duration, got %d s.', $seconds));
        }

        return new self($metres * self::SECONDS_PER_DAY / $seconds);
    }

    public function metresPerDay(): float
    {
        return $this->metresPerDay;
    }

    /**
     * The distance driven at this pace in $seconds, unrounded.
     */
    public function metresIn(int $seconds): float
    {
        return $this->metresPerDay * $seconds / self::SECONDS_PER_DAY;
    }
}
