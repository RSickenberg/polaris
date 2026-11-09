<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Shared\Reporting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Account\Message\DispatchOwnerMidnights;
use Polaris\Fetching\Message\DispatchDueFetches;
use Polaris\Shared\Reporting\WorkerHeartbeat;
use Sentry\CheckInStatus;
use Sentry\ClientInterface;
use Sentry\MonitorConfig;
use Sentry\Options;
use Sentry\State\HubInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;

#[CoversClass(WorkerHeartbeat::class)]
final class WorkerHeartbeatTest extends TestCase
{
    private const string DSN = 'https://owner@o2.ingest.sentry.io/2';

    public function testATickThatRanIsReportedOkUnderAnInstanceSlug(): void
    {
        $hub = $this->hub(self::DSN);
        $hub->expects(self::once())
            ->method('captureCheckIn')
            ->with(
                self::matchesRegularExpression('/^scheduler-[0-9a-f]{12}-dispatch-due-fetches$/'),
                self::equalTo(CheckInStatus::ok()),
                self::isFloat(),
                self::isInstanceOf(MonitorConfig::class),
            );
        $heartbeat = new WorkerHeartbeat($hub, 'secret');
        $envelope = $this->scheduled(new DispatchDueFetches());

        $heartbeat->onReceived(new WorkerMessageReceivedEvent($envelope, 'scheduler_default'));
        $heartbeat->onHandled(new WorkerMessageHandledEvent($envelope, 'scheduler_default'));
    }

    public function testATickThatFailedIsReportedAsAnError(): void
    {
        $hub = $this->hub(self::DSN);
        $hub->expects(self::once())
            ->method('captureCheckIn')
            ->with(
                self::matchesRegularExpression('/-dispatch-owner-midnights$/'),
                self::equalTo(CheckInStatus::error()),
            );
        $heartbeat = new WorkerHeartbeat($hub, 'secret');
        $envelope = $this->scheduled(new DispatchOwnerMidnights());

        $heartbeat->onReceived(new WorkerMessageReceivedEvent($envelope, 'scheduler_default'));
        $heartbeat->onFailed(new WorkerMessageFailedEvent($envelope, 'scheduler_default', new \RuntimeException('x')));
    }

    public function testTheSlugDependsOnTheSecretOfTheInstance(): void
    {
        $slugs = [];
        foreach (['one', 'two', 'one'] as $secret) {
            $hub = $this->hub(self::DSN);
            $hub->expects(self::once())->method('captureCheckIn')->willReturnCallback(
                static function (string $slug) use (&$slugs): null {
                    $slugs[] = $slug;

                    return null;
                },
            );
            $heartbeat = new WorkerHeartbeat($hub, $secret);
            $envelope = $this->scheduled(new DispatchDueFetches());
            $heartbeat->onReceived(new WorkerMessageReceivedEvent($envelope, 'scheduler_default'));
            $heartbeat->onHandled(new WorkerMessageHandledEvent($envelope, 'scheduler_default'));
        }

        self::assertCount(3, $slugs);
        self::assertSame($slugs[0], $slugs[2]);
        self::assertNotSame($slugs[0], $slugs[1]);
    }

    public function testWithoutADsnNothingIsSent(): void
    {
        $hub = $this->hub(null);
        $hub->expects(self::never())->method('captureCheckIn');
        $heartbeat = new WorkerHeartbeat($hub, 'secret');
        $envelope = $this->scheduled(new DispatchDueFetches());

        $heartbeat->onReceived(new WorkerMessageReceivedEvent($envelope, 'scheduler_default'));
        $heartbeat->onHandled(new WorkerMessageHandledEvent($envelope, 'scheduler_default'));
    }

    public function testAMessageThatDoesNotComeFromTheSchedulerIsIgnored(): void
    {
        $hub = $this->hub(self::DSN);
        $hub->expects(self::never())->method('captureCheckIn');
        $heartbeat = new WorkerHeartbeat($hub, 'secret');
        $envelope = new Envelope(new DispatchDueFetches());

        $heartbeat->onReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $heartbeat->onHandled(new WorkerMessageHandledEvent($envelope, 'async'));
    }

    private function scheduled(object $message): Envelope
    {
        $context = new MessageContext('default', 'id', $this->createStub(TriggerInterface::class), new \DateTimeImmutable());

        return new Envelope($message, [new ScheduledStamp($context)]);
    }

    private function hub(?string $dsn): HubInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $client = $this->createStub(ClientInterface::class);
        $client->method('getOptions')->willReturn(new Options(['dsn' => $dsn]));

        $hub = $this->createMock(HubInterface::class);
        $hub->method('getClient')->willReturn($client);

        return $hub;
    }
}
