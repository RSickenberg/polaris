<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Vehicle\EventListener;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Account\Message\OwnerMidnightPassed;
use Polaris\Shared\Reporting\ReportingContext;
use Polaris\Vehicle\EventListener\BindReportingUserOfMessage;
use Polaris\Vehicle\Service\VehicleOwnerLookup;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

#[CoversClass(BindReportingUserOfMessage::class)]
final class BindReportingUserOfMessageTest extends TestCase
{
    public function testAMessageAboutAnOwnerBindsThatOwner(): void
    {
        $context = new ReportingContext();
        $listener = new BindReportingUserOfMessage(
            $context,
            new VehicleOwnerLookup($this->createStub(Connection::class)),
        );
        $userId = '01J9ZQ3W0K7E4D9Y1V8T5B2N6A';

        $listener->onMessageReceived(
            new WorkerMessageReceivedEvent(new Envelope(new OwnerMidnightPassed($userId, '2026-11-07', 0)), 'async')
        );

        self::assertSame($userId, $context->userId());
    }

    public function testAMessageAboutNobodyClearsTheOwnerOfThePreviousOne(): void
    {
        $context = new ReportingContext();
        $context->enterUser('01J9ZQ3W0K7E4D9Y1V8T5B2N6A');
        $listener = new BindReportingUserOfMessage(
            $context,
            new VehicleOwnerLookup($this->createStub(Connection::class)),
        );

        $listener->onMessageReceived(new WorkerMessageReceivedEvent(new Envelope(new \stdClass()), 'async'));

        self::assertNull($context->userId());
    }
}
