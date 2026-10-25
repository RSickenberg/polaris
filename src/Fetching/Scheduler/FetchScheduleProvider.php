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
 * 5 minutes that queues the fetches that are due. The slots themselves are not here, because they depend
 * on each user's time zone and change with daylight saving: see {@see \Polaris\Fetching\Service\DueFetchDispatcher}.
 */
#[AsSchedule]
final readonly class FetchScheduleProvider implements ScheduleProviderInterface
{
    public const string TICK = '5 minutes';

    public function __construct(
        private CacheInterface $cache,
        private LockFactory $lockFactory,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return new Schedule()
            ->with(RecurringMessage::every(self::TICK, new DispatchDueFetches()))
            // A missed tick is not worth running late: the next one looks at the same slots.
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            // Two workers on the same Redis tick once, not twice.
            ->lock($this->lockFactory->createLock('polaris-fetch-schedule'));
    }
}
