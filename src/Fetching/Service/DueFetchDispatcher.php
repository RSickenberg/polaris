<?php

declare(strict_types=1);

namespace Polaris\Fetching\Service;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Fetching\Domain\FetchWindow;
use Polaris\Fetching\Domain\SlotCalculator;
use Polaris\Fetching\Entity\FetchSlotRun;
use Polaris\Fetching\Message\FetchOdometerMessage;
use Polaris\Fetching\Repository\FetchSlotRunRepository;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues one fetch per vehicle per slot that is due; a newer slot supersedes an older one that is still retrying. Slots are the local times of the owner turned into
 * UTC instants now, so a daylight saving change moves nothing by hand. A slot is recorded before its message
 * is queued and looked up first, so the tick can run as often as it likes (the unique (vehicle, slot) pair
 * backs this up if two ticks ever overlap). A slot whose window already closed (the worker was down) is not run late.
 */
final readonly class DueFetchDispatcher
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FetchSlotRunRepository $runs,
        private FetchScheduleService $schedules,
        private SlotRunLedger $ledger,
        private SlotCalculator $calculator,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int the number of fetches queued
     *
     * @throws ORMException
     * @throws ExceptionInterface
     */
    public function dispatch(): int
    {
        $now = CarbonImmutable::instance($this->clock->now());
        $queued = 0;

        foreach ($this->entityManager->getRepository(Vehicle::class)->findAll() as $vehicle) {
            $slots = $this->calculator->around($now, $vehicle->getOwner()->getTimeZone(), $this->schedules->slotsFor($vehicle));

            foreach ($slots as $slot) {
                if (!FetchWindow::isOpen($slot, $now) || null !== $this->runs->findAt($vehicle, $slot)) {
                    continue;
                }

                $this->queue($vehicle, $slot, $now);
                ++$queued;
            }
        }

        return $queued;
    }

    /**
     * @throws ORMException
     * @throws ExceptionInterface
     */
    private function queue(Vehicle $vehicle, CarbonImmutable $slot, CarbonImmutable $now): void
    {
        $this->ledger->supersedeOpen($vehicle, $now);
        $this->entityManager->persist(new FetchSlotRun($vehicle, $slot, $now));
        $this->entityManager->flush();

        $this->bus->dispatch(new FetchOdometerMessage($vehicle->getId()->toRfc4122(), $slot->getTimestamp()));
    }
}
