<?php

declare(strict_types=1);

namespace Polaris\Lease\Form\Model;

use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\Tolerance;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\DistanceUnit;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The lease terms as the user enters them: a yearly allowance, distances in the unit
 * they chose, a decimal cost and a percentage. LeaseContractFactory converts them to the
 * stored representation (a total in metres, minor units, basis points).
 *
 * Not readonly: the Form component writes the submitted values into it.
 */
final class LeaseContractData
{
    #[Assert\NotNull]
    public ?\DateTimeImmutable $startDate = null;

    #[Assert\NotNull]
    public ?\DateTimeImmutable $endDate = null;

    public EndDateConvention $endDateConvention = EndDateConvention::Exclusive;

    public DistanceUnit $distanceUnit = DistanceUnit::Kilometre;

    /** In {@see $distanceUnit}. */
    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $allowancePerYear = null;

    /** In {@see $distanceUnit}. */
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    public ?int $startOdometer = null;

    /** Per kilometre, in {@see $currency}, with at most two decimals, for example "0.12". */
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d{1,6}(\.\d{1,2})?$/', message: 'lease.excess_cost.format')]
    public ?string $excessCostPerKm = null;

    #[Assert\NotNull]
    public ?Currency $currency = null;

    #[Assert\NotNull]
    #[Assert\Range(min: 0, max: Tolerance::MAX_BASIS_POINTS / 100)]
    public int|float|null $tolerancePercent = 0;

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
