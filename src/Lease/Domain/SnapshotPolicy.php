<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

use Carbon\CarbonImmutable;
use Psr\Clock\ClockInterface;

/**
 * Which assessments are worth a daily snapshot, and which day a snapshot belongs to.
 */
final class SnapshotPolicy
{
    /**
     * An active lease is projected; an ended one still has a growing excess. A returned lease is final (the dashboard
     * shows its figures, they would repeat every day), and a draft is never produced by the assessor.
     */
    public static function covers(LeaseStatus $status): bool
    {
        return match ($status) {
            LeaseStatus::Active, LeaseStatus::Ended => true,
            LeaseStatus::Draft, LeaseStatus::Returned => false,
        };
    }

    /**
     * The UTC calendar day of the clock, as midnight UTC: the day a snapshot taken now belongs to.
     */
    public static function dayOf(ClockInterface $clock): CarbonImmutable
    {
        return CarbonImmutable::instance($clock->now())->utc()->startOfDay();
    }
}
