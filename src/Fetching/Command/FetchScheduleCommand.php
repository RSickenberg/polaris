<?php

declare(strict_types=1);

namespace Polaris\Fetching\Command;

use Doctrine\ORM\EntityManagerInterface;
use Polaris\Fetching\Domain\LocalTime;
use Polaris\Fetching\Service\FetchScheduleService;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shows or sets the local times at which the odometer is read. The times are in the time zone of the owner
 * of the vehicle; the scheduler turns them into UTC each day.
 */
#[AsCommand(name: 'polaris:fetch:schedule', description: 'Show or set the 1 or 2 daily local times at which the odometer is read')]
final readonly class FetchScheduleCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FetchScheduleService $schedules,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Argument(description: 'The first local time, HH:MM (24 hours). Omit it to show the current slots')]
        ?string $first = null,

        #[Argument(description: 'An optional second local time, HH:MM')]
        ?string $second = null,

        #[Option(description: 'The VIN of the vehicle. Optional when there is a single vehicle')]
        ?string $vin = null,
    ): int {
        $vehicle = $this->findVehicle($vin);

        if (!$vehicle instanceof Vehicle) {
            $io->error('No single vehicle found. Pass --vin, or add the vehicle first.');

            return Command::FAILURE;
        }

        if (null === $first) {
            $this->show($io, $vehicle);

            return Command::SUCCESS;
        }

        try {
            $this->schedules->change($vehicle, LocalTime::fromString($first), null === $second ? null : LocalTime::fromString($second));
        } catch (\InvalidArgumentException $invalid) {
            $io->error($invalid->getMessage());

            return Command::FAILURE;
        }

        $this->show($io, $vehicle);

        return Command::SUCCESS;
    }

    private function findVehicle(?string $vin): ?Vehicle
    {
        $repository = $this->entityManager->getRepository(Vehicle::class);

        if (null !== $vin) {
            return $repository->findOneBy(['vin' => strtoupper(trim($vin))]);
        }

        $all = $repository->findBy([], null, 2);

        return 1 === \count($all) ? $all[0] : null;
    }

    private function show(SymfonyStyle $io, Vehicle $vehicle): void
    {
        $io->success(\sprintf(
            'Odometer reads for %s at %s (%s).',
            $vehicle->getName(),
            implode(' and ', array_map(strval(...), $this->schedules->slotsFor($vehicle))),
            $vehicle->getOwner()->getTimeZone(),
        ));
    }
}
