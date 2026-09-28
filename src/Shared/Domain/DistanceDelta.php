<?php

declare(strict_types=1);

namespace Polaris\Shared\Domain;

/**
 * A signed difference between two distances, held as an integer number of metres.
 *
 * Use it where a result can go below zero, such as the margin left on an allowance;
 * {@see Distance} stays non-negative.
 */
final readonly class DistanceDelta
{
    private function __construct(
        private int $metres,
    ) {
    }

    /**
     * The distance to add to $from to reach $to: negative when $to is shorter.
     */
    public static function between(Distance $from, Distance $to): self
    {
        return new self($to->metres() - $from->metres());
    }

    public function metres(): int
    {
        return $this->metres;
    }

    public function isNegative(): bool
    {
        return $this->metres < 0;
    }

    public function absolute(): Distance
    {
        return Distance::fromMetres(abs($this->metres));
    }

    public function equals(self $other): bool
    {
        return $this->metres === $other->metres;
    }
}
