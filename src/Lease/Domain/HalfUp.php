<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

/**
 * Integer arithmetic rounded half up, so that no float touches a distance or an amount.
 */
final class HalfUp
{
    /**
     * round($value x $numerator / $denominator) for non-negative $value and $numerator and a
     * positive $denominator, as floor((2 x value x numerator + denominator) / (2 x denominator)).
     */
    public static function scale(int $value, int $numerator, int $denominator): int
    {
        if ($value < 0 || $numerator < 0 || $denominator <= 0) {
            throw new \InvalidArgumentException(\sprintf('Cannot scale %d x %d / %d.', $value, $numerator, $denominator));
        }

        if (0 === $numerator || 0 === $value) {
            return 0;
        }

        if ($value > intdiv(\PHP_INT_MAX - $denominator, 2 * $numerator)) {
            throw new \InvalidArgumentException(\sprintf('%d x %d is too large to scale.', $value, $numerator));
        }

        return intdiv(2 * $value * $numerator + $denominator, 2 * $denominator);
    }
}
