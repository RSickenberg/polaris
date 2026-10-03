<?php

declare(strict_types=1);

namespace Polaris\Lease\Service;

use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\AllowanceBasis;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\Tolerance;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Lease\Form\Model\LeaseContractData;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\Money;
use Polaris\Vehicle\Entity\Vehicle;

/**
 * Turns the lease terms entered by the user into a LeaseContract, or into the stored
 * representation of its terms.
 */
final class LeaseContractFactory
{
    /**
     * @throws \InvalidArgumentException when the data has not been validated first
     */
    public function create(Vehicle $vehicle, LeaseContractData $data): LeaseContract
    {
        $terms = $this->terms($data);

        return new LeaseContract($vehicle, $terms->term, $terms->allowance, $terms->startOdometer, $terms->excessCostPerKm, $terms->tolerance);
    }

    /**
     * @throws \InvalidArgumentException when the data has not been validated first
     */
    public function terms(LeaseContractData $data): LeaseTerms
    {
        if (!$data->isComplete()) {
            throw new \InvalidArgumentException('The lease contract data is incomplete: validate it before creating the contract.');
        }

        $term = LeaseTerm::fromCalendarDates($data->startDate, $data->endDate, $data->endDateConvention);
        $entered = Distance::from($data->allowance, $data->distanceUnit);

        return new LeaseTerms(
            $term,
            AllowanceBasis::PerYear === $data->allowanceBasis ? Allowance::fromYearly($entered, $term) : Allowance::ofTotal($entered),
            Distance::from($data->startOdometer, $data->distanceUnit),
            Money::fromDecimal($data->excessCostPerKm, $data->currency),
            Tolerance::fromPercent($data->tolerancePercent),
        );
    }
}
