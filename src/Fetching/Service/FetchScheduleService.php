<?php

declare(strict_types=1);

namespace Polaris\Fetching\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Fetching\Domain\LocalTime;
use Polaris\Fetching\Entity\FetchSchedule;
use Polaris\Vehicle\Entity\Vehicle;

/**
 * Reads and sets the local fetch slots of a vehicle.
 */
final readonly class FetchScheduleService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
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
