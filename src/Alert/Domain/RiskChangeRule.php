<?php

declare(strict_types=1);

namespace Polaris\Alert\Domain;

/**
 * Decides what a new risk level means against the last known one.
 *
 * Levels come as ranks (0 is the best, a higher rank is worse), because a Domain/ does not use another module's
 * Domain/: the Alert services turn a Lease risk level into a rank.
 */
final class RiskChangeRule
{
    public static function decide(?int $lastRank, int $currentRank): AlertDecision
    {
        if (null === $lastRank) {
            return AlertDecision::Baseline;
        }

        return match (true) {
            $currentRank === $lastRank => AlertDecision::Nothing,
            $currentRank > $lastRank => AlertDecision::Notify,
            default => AlertDecision::Baseline,
        };
    }
}
