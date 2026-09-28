<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

use Polaris\Shared\Domain\Distance;

/**
 * The total distance a lease contract allows over its whole term.
 *
 * The allowance is linear over the term, with no yearly reset: a yearly allowance is
 * converted once with total = per year x months / 12, rounded half up to the metre.
 */
final readonly class Allowance
{
    private function __construct(
        private Distance $total,
    ) {
        if (0 === $total->metres()) {
            throw new \InvalidArgumentException('A lease allowance must be greater than zero.');
        }
    }

    public static function ofTotal(Distance $total): self
    {
        return new self($total);
    }

    public static function fromYearly(Distance $perYear, LeaseTerm $term): self
    {
        $perYearMetres = $perYear->metres();
        $months = $term->months();

        if ($perYearMetres > intdiv(\PHP_INT_MAX - 12, 2 * $months)) {
            throw new \InvalidArgumentException('The yearly allowance is too large.');
        }

        // round(perYear x months / 12), half up, in integers: floor((2 x perYear x months + 12) / 24).
        return new self(Distance::fromMetres(intdiv(2 * $perYearMetres * $months + 12, 24)));
    }

    public function total(): Distance
    {
        return $this->total;
    }
}
