<?php

declare(strict_types=1);

namespace Polaris\Fetching\Scheduler;

use Polaris\Fetching\Message\DispatchDueFetches;
use Polaris\Fetching\Message\PurgeRefreshAttempts;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The one schedule of Polaris ("default", consumed as the `scheduler_default` transport): a tick every
 * minute that queues the fetches that are due, and a daily purge of old manual refreshes. The slots themselves are not here, because they depend
 * on each user's time zone and change with daylight saving: see {@see \Polaris\Fetching\Service\DueFetchDispatcher}.
 */
#[AsSchedule]
final readonly class FetchScheduleProvider implements ScheduleProviderInterface
{
    /**
     * Every minute on the minute (UTC). A fixed interval such as `every('5 minutes')` counts from the moment the worker
     * started, so a slot would be picked up at an arbitrary time up to 5 minutes late.
     */
    public const string TICK = '* * * * *';

    public const string PURGE = '17 3 * * *';

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
                // Once a day, at a quiet hour: the manual refreshes nobody waits for any more.
                RecurringMessage::cron(self::PURGE, new PurgeRefreshAttempts(), 'UTC'),
            )
            // A missed tick is not worth running late: the next one looks at the same slots.
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
        // No ->lock(): the scheduler would hold it for as long as the worker lives, and a killed worker leaves it behind
        // for several minutes, which silences every tick after a restart. Two workers are safe without it, because
        // DueFetchDispatcher queues a slot once (FetchSlotRun, unique per vehicle and slot).
    }
}
