<?php

declare(strict_types=1);

namespace Polaris\Shared\Reporting;

use Sentry\Logs\Log;

/**
 * `before_send_log`: the same rules as {@see EventFilter} for a log line.
 */
final readonly class LogFilter
{
    public function __construct(
        private ReportingGate $gate,
        private Scrubber $scrubber,
    ) {
    }

    public function __invoke(Log $log): ?Log
    {
        $decision = $this->gate->decide();
        if (!$decision->allowed) {
            return null;
        }

        $scrubbed = $this->scrubber->scrubLog($log, $decision->details);
        if (null !== $decision->userId) {
            $scrubbed->setAttribute('user.id', $decision->userId);
        }

        return $scrubbed;
    }
}
