<?php

declare(strict_types=1);

namespace Polaris\Lease\Service;

use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\Tolerance;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Lease\Form\Model\LeaseContractData;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\Money;
use Polaris\Vehicle\Entity\Vehicle;

/**
 * Turns the lease terms entered by the user into a LeaseContract.
 */
final class LeaseContractFactory
{
    /**
     * @throws \InvalidArgumentException when the data has not been validated first
     */
    public function create(Vehicle $vehicle, LeaseContractData $data): LeaseContract
    {
        if (null === $data->startDate || null === $data->endDate || null === $data->allowancePerYear
            || null === $data->startOdometer || null === $data->excessCostPerKm || null === $data->currency
            || null === $data->tolerancePercent
        ) {
            throw new \InvalidArgumentException('The lease contract data is incomplete: validate it before creating the contract.');
        }

        $term = LeaseTerm::fromCalendarDates($data->startDate, $data->endDate, $data->endDateConvention);

        return new LeaseContract(
            $vehicle,
            $term,
            Allowance::fromYearly(Distance::from($data->allowancePerYear, $data->distanceUnit), $term),
            Distance::from($data->startOdometer, $data->distanceUnit),
            Money::fromDecimal($data->excessCostPerKm, $data->currency),
            Tolerance::fromPercent($data->tolerancePercent),
        );
    }
}
