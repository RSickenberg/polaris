<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\DistanceDelta;
use Polaris\Shared\Domain\Money;

/**
 * Where a lease stands and how to drive from now on, at the {@see Projection::$asOf} of its projection.
 */
final readonly class LeaseAssessment
{
    public function __construct(
        public LeaseStatus $status,
        public Projection $projection,
        public RiskLevel $riskLevel,
        /** What is left of the allowance: zero once it is used up. */
        public Distance $remaining,
        /** Zero once the allowance is used up or the lease has ended. */
        public Budget $budget,
        /** The projected distance beyond the allowance; zero when the projection stays within it. */
        public Distance $projectedExcess,
        /** What the projected excess costs; zero when there is none. Once the lease has ended it is the cost so far. */
        public Money $projectedExcessCost,
        /**
         * The projected end minus the allowance, per week left: positive is the distance to
         * cut per week to land on the allowance, negative the room left per week. Null once
         * the lease has ended.
         */
        public ?DistanceDelta $weeklyCut,
    ) {
    }
}
