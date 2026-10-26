<?php

declare(strict_types=1);

namespace Polaris\Fetching\Message;

/**
 * The daily tick of the scheduler: deletes the manual refreshes nobody waits for any more.
 */
final readonly class PurgeRefreshAttempts
{
}
