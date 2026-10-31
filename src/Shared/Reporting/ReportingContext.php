<?php

declare(strict_types=1);

namespace Polaris\Shared\Reporting;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Whose data the code running now acts on, so that an error report can be held back for a user who opted out.
 *
 * Three states: no user (a boot error, the scheduler, a console command: the instance switch alone decides), a user
 * (a signed-in request, a message about one of their vehicles) and a user that could not be found (the report is dropped,
 * never sent). The web and the worker set it; a message never inherits the state of the previous one, as the worker
 * sets it again on every message it receives.
 */
final class ReportingContext implements ResetInterface
{
    private ?string $userId = null;

    private bool $unresolved = false;

    /**
     * @param string $userId the ULID (RFC 4122 form) of the user
     */
    public function enterUser(string $userId): void
    {
        $this->userId = $userId;
        $this->unresolved = false;
    }

    /**
     * The code acts for a user that cannot be resolved (a message about a vehicle that is gone).
     */
    public function enterUnresolved(): void
    {
        $this->userId = null;
        $this->unresolved = true;
    }

    public function leave(): void
    {
        $this->userId = null;
        $this->unresolved = false;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function isUnresolved(): bool
    {
        return $this->unresolved;
    }

    public function reset(): void
    {
        $this->leave();
    }
}
