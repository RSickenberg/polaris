<?php

declare(strict_types=1);

namespace Polaris\Fetching\Service;

use Carbon\CarbonImmutable;
use Polaris\Fetching\Domain\SlotRunStatus;
use Polaris\Fetching\Entity\FetchSlotRun;
use Polaris\Fetching\Repository\FetchSlotRunRepository;
use Polaris\Vehicle\Entity\Vehicle;

/**
 * Keeps one slot at a time alive per vehicle. A slot that is still waiting for its next check (the car was not
 * online) is dropped, as {@see SlotRunStatus::Superseded}, as soon as a newer slot comes due or a fetch reads the
 * car: its queued check would only repeat what is already known. The queued message is not removed from the queue,
 * it is ignored when it arrives (see {@see OdometerFetcher}). The caller flushes.
 */
final readonly class SlotRunLedger
{
    public function __construct(private FetchSlotRunRepository $runs)
    {
    }

    public function supersedeOpen(Vehicle $vehicle, CarbonImmutable $now, ?FetchSlotRun $except = null): void
    {
        foreach ($this->runs->findOpen($vehicle) as $run) {
            if ($run !== $except) {
                $run->record(SlotRunStatus::Superseded, $run->getAttempts(), $now);
            }
        }
    }
}
