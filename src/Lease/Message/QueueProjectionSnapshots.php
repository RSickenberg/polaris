<?php

declare(strict_types=1);

namespace Polaris\Lease\Message;

/**
 * The daily tick of the scheduler: queue one {@see SaveProjectionSnapshot} per lease.
 */
final readonly class QueueProjectionSnapshots
{
}
