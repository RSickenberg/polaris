<?php

declare(strict_types=1);

namespace Polaris\Fetching\Scheduler;

use Polaris\Fetching\Message\DispatchDueFetches;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The one schedule of Polaris ("default", consumed as the `scheduler_default` transport): a tick every
 * minute that queues the fetches that are due. The slots themselves are not here, because they depend
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

    public function __construct(
        private CacheInterface $cache,
        private LockFactory $lockFactory,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return new Schedule()
            ->with(RecurringMessage::cron(self::TICK, new DispatchDueFetches(), 'UTC'))
            // A missed tick is not worth running late: the next one looks at the same slots.
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            // Two workers on the same Redis tick once, not twice.
            ->lock($this->lockFactory->createLock('polaris-fetch-schedule'));
    }
}
