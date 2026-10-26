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
 * it is ignored when it arrives (see {@see OdometerFetcher}). The caller flushes. Slots whose window closed long ago
 * are deleted once a fetch has read the car (see {@see self::purgeOld()}).
 */
final readonly class SlotRunLedger
{
    /**
     * How long a slot is kept: its window closes 3 hours after the slot, so after a day nothing refers to it.
     */
    public const int RETENTION_HOURS = 24;

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

    /**
     * Deletes the slots of the vehicle older than {@see self::RETENTION_HOURS}, whatever their status. It runs
     * straight away, outside the unit of work: the caller flushes what it changed first, so that a slot it just
     * updated is not written after it was deleted.
     *
     * @return int how many slots were deleted
     */
    public function purgeOld(Vehicle $vehicle, CarbonImmutable $now): int
    {
        return $this->runs->deleteSlotsBefore($vehicle, $now->subHours(self::RETENTION_HOURS));
    }
}
