<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Service\Dto;

use Polaris\Vehicle\Entity\Vehicle;

/**
 * What an import did: the vehicles it created, and how many of the asked ones Polaris already had.
 */
final readonly class ImportResult
{
    /**
     * @param list<Vehicle> $added
     */
    public function __construct(
        public array $added,
        public int $alreadyAdded,
    ) {
    }
}
