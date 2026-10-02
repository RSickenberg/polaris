<?php

declare(strict_types=1);

namespace Polaris\Lease\Service;

use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\Tolerance;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\Money;

/**
 * The lease terms of a contract in their stored representation: Domain value objects,
 * converted from the user's input at the edge only.
 */
final readonly class LeaseTerms
{
    public function __construct(
        public LeaseTerm $term,
        public Allowance $allowance,
        public Distance $startOdometer,
        public Money $excessCostPerKm,
        public Tolerance $tolerance,
    ) {
    }
}
