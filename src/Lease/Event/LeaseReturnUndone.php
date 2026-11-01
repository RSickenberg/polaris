<?php

declare(strict_types=1);

namespace Polaris\Lease\Event;

use Polaris\Vehicle\Entity\Vehicle;

/**
 * The user undid the return of a vehicle: its lease is assessed again.
 */
final readonly class LeaseReturnUndone
{
    public function __construct(public Vehicle $vehicle)
    {
    }
}
