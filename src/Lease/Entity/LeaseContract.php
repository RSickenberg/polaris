<?php

declare(strict_types=1);

namespace Polaris\Lease\Entity;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\Tolerance;
use Polaris\Shared\Doctrine\Type\CarbonDateImmutableType;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\Money;
use Polaris\Vehicle\Entity\Vehicle;

/**
 * The terms of a vehicle's lease.
 *
 * Columns hold scalars (metres, minor units, basis points); the constructor and getters
 * take and return the Domain value objects, which validate every value.
 */
#[ORM\Entity]
class LeaseContract
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Vehicle $vehicle;

    #[ORM\Column(type: CarbonDateImmutableType::NAME)]
    private CarbonImmutable $startDate;

    #[ORM\Column(type: CarbonDateImmutableType::NAME)]
    private CarbonImmutable $endDate;

    #[ORM\Column(length: 16, enumType: EndDateConvention::class)]
    private EndDateConvention $endDateConvention;

    #[ORM\Column]
    private int $allowanceMetres;

    #[ORM\Column]
    private int $startOdometerMetres;

    #[ORM\Column]
    private int $excessCostPerKmMinor;

    #[ORM\Column(length: 3, enumType: Currency::class)]
    private Currency $currency;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $toleranceBasisPoints;

    public function __construct(
        Vehicle $vehicle,
        LeaseTerm $term,
        Allowance $allowance,
        Distance $startOdometer,
        Money $excessCostPerKm,
        Tolerance $tolerance,
    ) {
        if ($excessCostPerKm->isNegative()) {
            throw new \InvalidArgumentException('The excess cost per km cannot be negative.');
        }

        $this->vehicle = $vehicle;
        $this->startDate = $term->start();
        $this->endDate = $term->end();
        $this->endDateConvention = $term->endDateConvention();
        $this->allowanceMetres = $allowance->total()->metres();
        $this->startOdometerMetres = $startOdometer->metres();
        $this->excessCostPerKmMinor = $excessCostPerKm->minorAmount();
        $this->currency = $excessCostPerKm->currency();
        $this->toleranceBasisPoints = $tolerance->basisPoints();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVehicle(): Vehicle
    {
        return $this->vehicle;
    }

    public function getTerm(): LeaseTerm
    {
        return new LeaseTerm($this->startDate, $this->endDate, $this->endDateConvention);
    }

    public function getAllowance(): Allowance
    {
        return Allowance::ofTotal(Distance::fromMetres($this->allowanceMetres));
    }

    public function getStartOdometer(): Distance
    {
        return Distance::fromMetres($this->startOdometerMetres);
    }

    public function getExcessCostPerKm(): Money
    {
        return Money::ofMinor($this->excessCostPerKmMinor, $this->currency);
    }

    public function getTolerance(): Tolerance
    {
        return Tolerance::fromBasisPoints($this->toleranceBasisPoints);
    }
}
