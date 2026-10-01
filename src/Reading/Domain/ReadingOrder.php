<?php

declare(strict_types=1);

namespace Polaris\Reading\Domain;

use Polaris\Shared\Domain\OdometerSample;

/**
 * The rule that keeps a vehicle's odometer readings in order: an odometer never goes
 * down, so over time the values must not decrease.
 *
 * A reading entered after the latest one is compared with that one. A reading entered
 * between two stored ones (a backfill) must fit between them. A value equal to a
 * neighbour is accepted: the car did not move.
 */
final class ReadingOrder
{
    /**
     * @param OdometerSample|null $previous the latest stored reading before the new one, if any
     * @param OdometerSample|null $next     the earliest stored reading after the new one, if any
     *
     * @return ReadingRejection|null null when the new reading fits between its neighbours
     */
    public function check(OdometerSample $new, ?OdometerSample $previous, ?OdometerSample $next): ?ReadingRejection
    {
        if (null !== $previous && $new->odometer()->metres() < $previous->odometer()->metres()) {
            return ReadingRejection::LowerThanPrevious;
        }

        if (null !== $next && $new->odometer()->metres() > $next->odometer()->metres()) {
            return ReadingRejection::HigherThanNext;
        }

        return null;
    }
}
