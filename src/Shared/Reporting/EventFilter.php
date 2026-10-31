<?php

declare(strict_types=1);

namespace Polaris\Shared\Reporting;

use Sentry\Event;
use Sentry\EventHint;
use Sentry\UserDataBag;

/**
 * `before_send` and `before_send_transaction`: holds back what a user opted out of, scrubs the rest, and attaches the
 * user ULID (nothing else about the user) when the report belongs to one.
 */
final readonly class EventFilter
{
    public function __construct(
        private ReportingGate $gate,
        private Scrubber $scrubber,
    ) {
    }

    public function __invoke(Event $event, ?EventHint $hint = null): ?Event
    {
        $decision = $this->gate->decide();
        if (!$decision->allowed) {
            return null;
        }

        $event->setUser(null === $decision->userId ? null : UserDataBag::createFromUserIdentifier($decision->userId));

        return $this->scrubber->scrubEvent($event, $decision->details);
    }
}
