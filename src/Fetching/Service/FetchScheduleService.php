<?php

declare(strict_types=1);

namespace Polaris\Fetching\Service;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Fetching\Domain\LocalTime;
use Polaris\Fetching\Domain\SlotCalculator;
use Polaris\Fetching\Entity\FetchSchedule;
use Polaris\Vehicle\Entity\Vehicle;

/**
 * Reads and sets the local fetch slots of a vehicle.
 */
final readonly class FetchScheduleService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SlotCalculator $calculator,
    ) {
    }

    /**
     * The slots of the vehicle in the time zone of its owner, the default one when none was set.
     *
     * @return non-empty-list<LocalTime>
     */
    public function slotsFor(Vehicle $vehicle): array
    {
        $schedule = $this->entityManager->getRepository(FetchSchedule::class)->findOneBy(['vehicle' => $vehicle]);

        return $schedule instanceof FetchSchedule ? $schedule->getSlots() : [LocalTime::default()];
    }

    /**
     * Whether the vehicle has no stored slots and so reads at the default one.
     */
    public function isDefault(Vehicle $vehicle): bool
    {
        return null === $this->entityManager->getRepository(FetchSchedule::class)->findOneBy(['vehicle' => $vehicle]);
    }

    /**
     * The next slot after $now, as a UTC instant.
     *
     * @throws \InvalidArgumentException when the time zone of the owner is not an IANA name
     */
    public function nextSlot(Vehicle $vehicle, CarbonImmutable $now): CarbonImmutable
    {
        $slots = $this->slotsFor($vehicle);
        $zone = $vehicle->getOwner()->getTimeZone();
        $instants = [...$this->calculator->around($now, $zone, $slots), ...$this->calculator->around($now->addDay(), $zone, $slots)];

        foreach ($instants as $instant) {
            if ($instant->greaterThan($now)) {
                return $instant;
            }
        }

        throw new \LogicException('A slot is always found within two days.');
    }

    /**
     * @throws \InvalidArgumentException when both slots are the same time
     * @throws ORMException
     */
    public function change(Vehicle $vehicle, LocalTime $first, ?LocalTime $second = null): FetchSchedule
    {
        $schedule = $this->entityManager->getRepository(FetchSchedule::class)->findOneBy(['vehicle' => $vehicle]);

        if ($schedule instanceof FetchSchedule) {
            $schedule->change($first, $second);
        } else {
            $schedule = new FetchSchedule($vehicle, $first, $second);
            $this->entityManager->persist($schedule);
        }

        $this->entityManager->flush();

        return $schedule;
    }
}
