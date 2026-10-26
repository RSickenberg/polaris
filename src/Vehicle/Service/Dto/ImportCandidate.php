<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Service\Dto;

use Polaris\Tesla\Service\Dto\VehicleSummary;
use Polaris\Vehicle\Entity\Vehicle;

/**
 * A vehicle of the Tesla account, and whether Polaris already has it.
 */
final readonly class ImportCandidate
{
    public function __construct(
        public VehicleSummary $summary,
        public bool $added,
    ) {
    }

    /**
     * The name the vehicle gets when imported: the one set in the Tesla app, else "Tesla" and the last
     * 4 characters of the VIN.
     */
    public function name(): string
    {
        $name = trim($this->summary->displayName ?? '');

        if ('' === $name) {
            return 'Tesla ' . substr($this->summary->vin->value(), -4);
        }

        return mb_substr($name, 0, Vehicle::NAME_MAX_LENGTH);
    }
}
