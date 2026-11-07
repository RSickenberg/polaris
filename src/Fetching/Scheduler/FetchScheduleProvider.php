<?php

declare(strict_types=1);

namespace Polaris\Fetching\Scheduler;

use Polaris\Account\Message\DispatchOwnerMidnights;
use Polaris\Fetching\Message\DispatchDueFetches;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The one schedule of Polaris ("default", consumed as the `scheduler_default` transport). It has two entries, both a
 * tick every minute: the fetches that are due, and the owners whose local midnight has just passed. There is no daily
 * entry and no UTC time of day: everything that depends on a day depends on an owner's time zone, which changes with
 * daylight saving and with the user's settings, so it is worked out at every tick. The fetch slots are in
 * {@see \Polaris\Fetching\Service\DueFetchDispatcher}; the daily jobs (the projection snapshots of the Lease module, the
 * purge of old manual refreshes) run when {@see \Polaris\Account\Service\OwnerMidnightDispatcher} queues an
 * `OwnerMidnightPassed`, once per owner and local day.
 */
#[AsSchedule]
final readonly class FetchScheduleProvider implements ScheduleProviderInterface
{
    /**
     * Every minute on the minute. A fixed interval such as `every('5 minutes')` counts from the moment the worker
     * started, so a slot would be picked up at an arbitrary time up to 5 minutes late.
     */
    public const string TICK = '* * * * *';

    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return new Schedule()
            // One call: with() starts a new schedule from the messages it is given, it does not add to the previous ones.
            ->with(
                RecurringMessage::cron(self::TICK, new DispatchDueFetches(), 'UTC'),
                // The owners whose local midnight passed (a minute tick, not a daily cron: the day is the owner's).
                RecurringMessage::cron(self::TICK, new DispatchOwnerMidnights(), 'UTC'),
            )
            // A missed tick is not worth running late: the next one looks at the same slots, and at the same owners' midnights.
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
        // No ->lock(): the scheduler would hold it for as long as the worker lives, and a killed worker leaves it behind
        // for several minutes, which silences every tick after a restart. Two workers are safe without it, because
        // DueFetchDispatcher queues a slot once (FetchSlotRun, unique per vehicle and slot) and OwnerMidnightDispatcher claims a
        // local day once (one atomic UPDATE of the user).
    }
}
