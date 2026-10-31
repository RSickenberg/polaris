<?php

declare(strict_types=1);

namespace Polaris\Reading\Event;

use Polaris\Vehicle\Entity\Vehicle;

/**
 * The car still had the latest reading later (it had not moved): the projection goes on from that instant.
 */
final readonly class ReadingConfirmed
{
    public function __construct(public Vehicle $vehicle)
    {
    }
}
