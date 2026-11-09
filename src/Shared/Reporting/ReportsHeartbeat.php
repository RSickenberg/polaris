<?php

declare(strict_types=1);

namespace Polaris\Shared\Reporting;

/**
 * A scheduler tick whose runs are reported to Sentry Crons by {@see WorkerHeartbeat}. Only one tick carries it: Sentry
 * bills every monitor, and the ticks run in the same worker.
 */
interface ReportsHeartbeat
{
}
