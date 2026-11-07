<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

/**
 * Which assessments are worth a daily snapshot. The day a snapshot belongs to is the local day of the owner's midnight
 * ({@see \Polaris\Shared\Domain\OwnerMidnight}), not a UTC day.
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
}
