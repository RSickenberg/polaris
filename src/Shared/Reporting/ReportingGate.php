<?php

declare(strict_types=1);

namespace Polaris\Shared\Reporting;

/**
 * Decides, at the moment the SDK is about to send something, whether it may leave.
 *
 * The instance switch is the DSN itself: without one the SDK never calls the hooks. Here only the user counts. No user in
 * the context: the instance switch alone. A user: their choice, read now (an opt-out takes effect on the next report).
 * A user that cannot be found, or a choice that cannot be read: nothing is sent.
 */
final readonly class ReportingGate
{
    public function __construct(
        private ReportingContext $context,
        private ReportingPreferences $preferences,
    ) {
    }

    public function decide(): ReportingDecision
    {
        if ($this->context->isUnresolved()) {
            return ReportingDecision::drop();
        }

        $userId = $this->context->userId();
        if (null === $userId) {
            return ReportingDecision::instanceOnly();
        }

        try {
            $preference = $this->preferences->of($userId);
        } catch (\Throwable) {
            return ReportingDecision::drop();
        }

        if (null === $preference || !$preference->enabled) {
            return ReportingDecision::drop();
        }

        return ReportingDecision::forUser($userId, $preference->details);
    }
}
