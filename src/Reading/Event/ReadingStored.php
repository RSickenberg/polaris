<?php

declare(strict_types=1);

namespace Polaris\Reading\Event;

use Polaris\Vehicle\Entity\Vehicle;

/**
 * A new odometer reading was stored, typed by the user or read from the car.
 */
final readonly class ReadingStored
{
    public function __construct(public Vehicle $vehicle)
    {
    }
}
