<?php

declare(strict_types=1);

namespace Polaris\Lease\Event;

use Polaris\Vehicle\Entity\Vehicle;

/**
 * The lease contract of a vehicle was created or edited (the form fires it on every save, even unchanged).
 */
final readonly class LeaseTermsSaved
{
    public function __construct(
        public Vehicle $vehicle,
        /**
         * True when something a projection depends on changed on an existing contract (lease start, end date,
         * allowance, start odometer): the stored snapshots no longer match the terms. False for a new contract (it
         * has no snapshot) and for an edit that left those unchanged.
         */
        public bool $projectionChanged = false,
    ) {
    }
}
