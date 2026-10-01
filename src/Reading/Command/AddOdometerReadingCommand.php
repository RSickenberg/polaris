<?php

declare(strict_types=1);

namespace Polaris\Reading\Command;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Polaris\Reading\Form\Model\OdometerReadingData;
use Polaris\Reading\Service\OdometerReadingService;
use Polaris\Reading\Service\ReadingRejected;
use Polaris\Shared\Domain\DistanceUnit;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'polaris:odometer:add', description: 'Record a manual odometer reading')]
final readonly class AddOdometerReadingCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OdometerReadingService $service,
        private ValidatorInterface $validator,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The odometer value, in the unit given by --unit')]
        int $odometer,
        #[Option(description: 'Unit of the value: km or mi')]
        string $unit = 'km',
        #[Option(description: 'When it was read, as an ISO 8601 date and time; UTC when it has no offset. Defaults to now')]
        ?string $at = null,
        #[Option(description: 'The vehicle, by VIN or id. Optional when only one vehicle exists')]
        ?string $vehicle = null,
    ): int {
        $distanceUnit = DistanceUnit::tryFrom($unit);
        if (null === $distanceUnit) {
            $io->error('The unit must be "km" or "mi".');

            return Command::INVALID;
        }

        try {
            $readAt = null === $at ? CarbonImmutable::instance($this->clock->now()) : CarbonImmutable::parse($at, 'UTC');
        } catch (\Exception) {
            $io->error(\sprintf('"%s" is not a valid date and time.', $at));

            return Command::INVALID;
        }

        $vehicleEntity = $this->findVehicle($vehicle, $io);
        if (null === $vehicleEntity) {
            return Command::INVALID;
        }

        $data = new OdometerReadingData();
        $data->readAt = $readAt;
        $data->distanceUnit = $distanceUnit;
        $data->odometer = $odometer;

        $violations = $this->validator->validate($data);
        if (\count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error(\sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage()));
            }

            return Command::INVALID;
        }

        try {
            $reading = $this->service->recordManual($vehicleEntity, $data);
        } catch (ReadingRejected $rejected) {
            $io->error($this->translator->trans($rejected->reason->value, $rejected->parameters, 'reading'));

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            'Recorded %d %s for %s at %s UTC.',
            $odometer,
            $distanceUnit->value,
            $vehicleEntity->getName(),
            $reading->getReadAt()->toIso8601ZuluString('minute'),
        ));

        return Command::SUCCESS;
    }

    private function findVehicle(?string $identifier, SymfonyStyle $io): ?Vehicle
    {
        $repository = $this->entityManager->getRepository(Vehicle::class);

        if (null === $identifier) {
            $vehicles = $repository->findAll();
            if (1 !== \count($vehicles)) {
                $io->error(0 === \count($vehicles) ? 'There is no vehicle yet.' : 'There are several vehicles: choose one with --vehicle (VIN or id).');

                return null;
            }

            return $vehicles[0];
        }

        $vehicle = Ulid::isValid($identifier)
            ? $repository->find(Ulid::fromString($identifier))
            : $repository->findOneBy(['vin' => strtoupper($identifier)]);

        if (null === $vehicle) {
            $io->error(\sprintf('No vehicle matches "%s".', $identifier));
        }

        return $vehicle;
    }
}
