<?php

declare(strict_types=1);

namespace Polaris\Lease\Service\Enum;

enum SnapshotOutcome: string
{
    /** The first snapshot of the day. */
    case Saved = 'saved';

    /** The day already had one: its figures were replaced. */
    case Updated = 'updated';

    case SkippedNoContract = 'no contract';

    /** No odometer reading after the lease start yet. */
    case SkippedNoData = 'no data';

    /** The car went back: the figures are final, nothing new to keep. */
    case SkippedReturned = 'returned';

    /** The readings and the terms contradict each other, or the lease is a draft. */
    case SkippedNotAssessable = 'not assessable';

    /** Another snapshot of the lease is being saved: try again later. */
    case Busy = 'busy';

    /** Another writer stored the day's row between the lookup and the insert; nothing was changed here. */
    case Concurrent = 'concurrent';

    public function isStored(): bool
    {
        return self::Saved === $this || self::Updated === $this;
    }
}
