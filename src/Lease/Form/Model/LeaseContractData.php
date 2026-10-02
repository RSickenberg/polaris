<?php

declare(strict_types=1);

namespace Polaris\Lease\Form\Model;

use Polaris\Lease\Domain\AllowanceBasis;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\Tolerance;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\DistanceUnit;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The lease terms as the user enters them: an allowance per year or in total, distances in
 * the unit they chose, a decimal cost and a percentage. LeaseContractFactory converts them to the
 * stored representation (a total in metres, minor units, basis points).
 *
 * Not readonly: the Form component writes the submitted values into it.
 */
final class LeaseContractData
{
    /** Far above any real lease, and low enough for the projection to stay within integer range. */
    public const int MAX_ALLOWANCE = 500_000;
    public const int MAX_ODOMETER = 5_000_000;

    #[Assert\NotNull]
    public ?\DateTimeImmutable $startDate = null;

    #[Assert\NotNull]
    public ?\DateTimeImmutable $endDate = null;

    public EndDateConvention $endDateConvention = EndDateConvention::Exclusive;

    public DistanceUnit $distanceUnit = DistanceUnit::Kilometre;

    public AllowanceBasis $allowanceBasis = AllowanceBasis::PerYear;

    /** In {@see $distanceUnit}, per year or in total as {@see $allowanceBasis} says. */
    #[Assert\NotNull]
    #[Assert\Positive]
    #[Assert\LessThanOrEqual(self::MAX_ALLOWANCE)]
    public int|float|null $allowance = null;

    /** In {@see $distanceUnit}. */
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    #[Assert\LessThanOrEqual(self::MAX_ODOMETER)]
    public int|float|null $startOdometer = null;

    /** Per kilometre, in {@see $currency}, with at most two decimals, for example "0.12". */
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d{1,6}(\.\d{1,2})?$/', message: 'lease.excess_cost.format')]
    public ?string $excessCostPerKm = null;

    #[Assert\NotNull]
    public ?Currency $currency = null;

    #[Assert\NotNull]
    #[Assert\Range(min: 0, max: Tolerance::MAX_BASIS_POINTS / 100)]
    public int|float|null $tolerancePercent = 0;

    /**
     * The stored contract as the form shows it, in kilometres: per year when the total divides
     * exactly into a yearly distance, so that saving it unchanged gives the same total.
     */
    public static function fromContract(LeaseContract $contract): self
    {
        $term = $contract->getTerm();
        $totalMetres = $contract->getAllowance()->total()->metres();

        $data = new self();
        $data->startDate = $term->start();
        $data->endDate = $term->end();
        $data->endDateConvention = $term->endDateConvention();
        $data->distanceUnit = DistanceUnit::Kilometre;

        if (0 === ($totalMetres * 12) % $term->months()) {
            $data->allowanceBasis = AllowanceBasis::PerYear;
            $data->allowance = intdiv($totalMetres * 12, $term->months()) / 1000;
        } else {
            $data->allowanceBasis = AllowanceBasis::Total;
            $data->allowance = $totalMetres / 1000;
        }

        $data->startOdometer = $contract->getStartOdometer()->metres() / 1000;
        $data->excessCostPerKm = $contract->getExcessCostPerKm()->toDecimal();
        $data->currency = $contract->getExcessCostPerKm()->currency();
        $data->tolerancePercent = $contract->getTolerance()->percent();

        return $data;
    }

    #[Assert\Callback]
    public function validateTerm(ExecutionContextInterface $context): void
    {
        if (null === $this->startDate || null === $this->endDate) {
            return;
        }

        try {
            LeaseTerm::fromCalendarDates($this->startDate, $this->endDate, $this->endDateConvention);
        } catch (\InvalidArgumentException) {
            $context->buildViolation('lease.term.whole_months')
                ->setParameter('{{ end }}', EndDateConvention::Inclusive === $this->endDateConvention ? '2029-01-14' : '2029-01-15')
                ->atPath('endDate')
                ->addViolation();
        }
    }
}
