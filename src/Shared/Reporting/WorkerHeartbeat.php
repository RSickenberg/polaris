<?php

declare(strict_types=1);

namespace Polaris\Shared\Reporting;

use Polaris\Fetching\Message\DispatchDueFetches;
use Sentry\CheckInStatus;
use Sentry\MonitorConfig;
use Sentry\MonitorSchedule;
use Sentry\State\HubInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;

/**
 * Tells Sentry Crons that the scheduler tick of this instance ran, so that a worker that died, hung or cannot reach its
 * database raises a "missed" or "failed" alert instead of staying silent. Only messages that come out of the scheduler
 * count (they carry a {@see ScheduledStamp}), and only the fetch tick: both ticks run in the same worker, so one
 * monitor is enough to see it die, and Sentry bills every monitor (the free plan includes one).
 *
 * It is sent only to the owner's own Sentry project (`POLARIS_ERROR_REPORTING=custom`): every instance gets its own
 * monitor, and Sentry bills each one, so the maintainer's project would pay for every install. With `maintainer`, `off`
 * or without a DSN nothing is sent. A tick belongs to no user, so there is no user choice to apply, and a check-in
 * carries only a monitor slug, a status and a duration.
 *
 * The slug holds an instance id, `hash_hmac` of the app secret: stable, the same for every tick of an instance, and it
 * names neither the host nor the secret. Several instances reporting to one Sentry project each get their own monitors.
 */
#[When(env: 'prod')]
final class WorkerHeartbeat
{
    /**
     * When each scheduled message was received, keyed by the object id of the message: the envelope is rebuilt
     * between the events, the message itself is not.
     *
     * @var array<int, float>
     */
    private array $startedAt = [];

    public function __construct(
        private readonly HubInterface $hub,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        #[Autowire('%env(POLARIS_ERROR_REPORTING)%')]
        private readonly string $reportingSwitch,
    ) {
    }

    #[AsEventListener]
    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        if ($this->isScheduled($event->getEnvelope())) {
            $this->startedAt[spl_object_id($event->getEnvelope()->getMessage())] = microtime(true);
        }
    }

    #[AsEventListener]
    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $this->checkIn($event->getEnvelope(), CheckInStatus::ok());
    }

    #[AsEventListener]
    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $this->checkIn($event->getEnvelope(), CheckInStatus::error());
    }

    private function checkIn(Envelope $envelope, CheckInStatus $status): void
    {
        $message = $envelope->getMessage();
        $key = spl_object_id($message);
        $started = $this->startedAt[$key] ?? null;
        unset($this->startedAt[$key]);

        if (null === $started || !$this->isScheduled($envelope) || !$this->isReporting()) {
            return;
        }

        $this->hub->captureCheckIn(
            slug: $this->slug($message),
            status: $status,
            duration: microtime(true) - $started,
            monitorConfig: new MonitorConfig(
                schedule: MonitorSchedule::crontab('* * * * *'),
                // Two missed minutes before "missed": a tick may start a little late when the worker is busy.
                checkinMargin: 2,
                maxRuntime: 5,
                timezone: 'UTC',
                // Three failed ticks in a row open an issue, one recovered tick closes it.
                failureIssueThreshold: 3,
                recoveryThreshold: 1,
            ),
        );
    }

    private function isScheduled(Envelope $envelope): bool
    {
        return null !== $envelope->last(ScheduledStamp::class)
            && $envelope->getMessage() instanceof DispatchDueFetches;
    }

    private function isReporting(): bool
    {
        return ErrorReportingMode::Custom === ErrorReportingMode::fromSwitch($this->reportingSwitch)
            && null !== $this->hub->getClient()?->getOptions()->getDsn();
    }

    private function slug(object $message): string
    {
        $instance = substr(hash_hmac('sha256', 'polaris.worker-heartbeat', $this->secret), 0, 12);
        $class = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', new \ReflectionClass($message)->getShortName()));

        return \sprintf('scheduler-%s-%s', $instance, $class);
    }
}
