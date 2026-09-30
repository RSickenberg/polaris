<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\Money;

/**
 * The cost of driving beyond the allowance: excess x price per km, from the metres, in
 * integers, rounded half up once to the minor unit.
 */
final class ExcessCost
{
    private const int METRES_PER_KILOMETRE = 1_000;

    public static function of(Distance $excess, Money $pricePerKm): Money
    {
        if ($pricePerKm->isNegative()) {
            throw new \InvalidArgumentException('The excess cost per km cannot be negative.');
        }

        return Money::ofMinor(
            HalfUp::scale($excess->metres(), $pricePerKm->minorAmount(), self::METRES_PER_KILOMETRE),
            $pricePerKm->currency(),
        );
    }
}
