<?php

declare(strict_types=1);

namespace Polaris\Vehicle\EventListener;

use Polaris\Shared\Reporting\ReportingContext;
use Polaris\Shared\Reporting\ReportsForVehicle;
use Polaris\Vehicle\Service\VehicleOwnerLookup;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * The worker tells the error reporting whose vehicle the message is about, before the handler runs. A message about no
 * vehicle (the scheduler tick, the purge) clears it, so one message never inherits the user of the previous one. A
 * vehicle that cannot be found leaves the context unresolved: nothing is sent (fail closed).
 *
 * The context is not cleared when the handler ends: the SDK finishes the transaction of the message after that, and
 * it must still know the user. The next message sets it again.
 */
final readonly class BindReportingUserOfMessage
{
    public function __construct(
        private ReportingContext $context,
        private VehicleOwnerLookup $owners,
    ) {
    }

    #[AsEventListener(priority: 100)]
    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();

        if (!$message instanceof ReportsForVehicle) {
            $this->context->leave();

            return;
        }

        try {
            $ownerId = $this->owners->ownerIdOf($message->reportingVehicleId());
        } catch (\Throwable) {
            $ownerId = null;
        }

        if (null === $ownerId) {
            $this->context->enterUnresolved();

            return;
        }

        $this->context->enterUser($ownerId);
    }
}
