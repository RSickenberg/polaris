<?php

declare(strict_types=1);

namespace Polaris\Shared\Domain;

/**
 * The ISO 4217 currencies Polaris supports. Add a case to support another one.
 */
enum Currency: string
{
    case CHF = 'CHF';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case USD = 'USD';

    /**
     * The number of decimal digits of the minor unit (2 for cents and centimes).
     */
    public function minorUnits(): int
    {
        return match ($this) {
            self::CHF, self::EUR, self::GBP, self::USD => 2,
        };
    }
}
