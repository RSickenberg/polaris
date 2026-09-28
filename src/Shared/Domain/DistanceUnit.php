<?php

declare(strict_types=1);

namespace Polaris\Shared\Domain;

/**
 * A unit a distance is entered or displayed in. Distances are always stored in metres.
 */
enum DistanceUnit: string
{
    case Kilometre = 'km';
    case Mile = 'mi';

    /**
     * The length of one unit in metres (1 mi = 1.609344 km, exactly).
     */
    public function metres(): float
    {
        return match ($this) {
            self::Kilometre => 1000.0,
            self::Mile => 1609.344,
        };
    }
}
