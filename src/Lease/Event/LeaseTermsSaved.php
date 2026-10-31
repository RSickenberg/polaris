<?php

declare(strict_types=1);

namespace Polaris\Lease\Event;

use Polaris\Vehicle\Entity\Vehicle;

/**
 * The lease contract of a vehicle was created or edited.
 */
final readonly class LeaseTermsSaved
{
    public function __construct(public Vehicle $vehicle)
    {
    }
}
