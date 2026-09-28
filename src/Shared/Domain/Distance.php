<?php

declare(strict_types=1);

namespace Polaris\Shared\Domain;

/**
 * A non-negative distance, held as an integer number of metres.
 *
 * Values entered in kilometres or miles are converted to metres once, rounded half up,
 * so that storage and calculations never depend on the unit the user chose.
 */
final readonly class Distance
{
    private function __construct(
        private int $metres,
    ) {
        if ($metres < 0) {
            throw new \InvalidArgumentException(\sprintf('A distance cannot be negative, got %d m.', $metres));
        }
    }

    public static function fromMetres(int $metres): self
    {
        return new self($metres);
    }

    public static function fromKilometres(int|float $kilometres): self
    {
        return self::from($kilometres, DistanceUnit::Kilometre);
    }

    public static function fromMiles(int|float $miles): self
    {
        return self::from($miles, DistanceUnit::Mile);
    }

    public static function from(int|float $value, DistanceUnit $unit): self
    {
        if (!is_finite((float) $value)) {
            throw new \InvalidArgumentException('A distance must be a finite number.');
        }

        $metres = round($value * $unit->metres());

        // Checked before the cast: PHP 8.5 deprecates casting an out-of-range float to int.
        if ($metres < 0) {
            throw new \InvalidArgumentException(\sprintf('A distance cannot be negative, got %s %s.', $value, $unit->value));
        }

        if ($metres >= (float) \PHP_INT_MAX) {
            throw new \InvalidArgumentException(\sprintf('The distance %s %s is too large.', $value, $unit->value));
        }

        return new self((int) $metres);
    }

    public function metres(): int
    {
        return $this->metres;
    }

    /**
     * The distance expressed in the given unit, for display.
     */
    public function in(DistanceUnit $unit): float
    {
        return $this->metres / $unit->metres();
    }

    public function equals(self $other): bool
    {
        return $this->metres === $other->metres;
    }
}
