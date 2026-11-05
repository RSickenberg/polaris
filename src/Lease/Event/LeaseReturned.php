<?php

declare(strict_types=1);

namespace Polaris\Lease\Event;

use Polaris\Vehicle\Entity\Vehicle;

/**
 * The user marked the car of a vehicle as returned to the bank, or moved the date.
 */
final readonly class LeaseReturned
{
    public function __construct(public Vehicle $vehicle)
    {
    }
}
