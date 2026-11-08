<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Polaris\Vehicle\Service\Enum\LeaseImportOutcome;
use Polaris\Vehicle\Service\Exception\VehicleDataRejected;
use Polaris\Vehicle\Service\VehicleDataImporter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Adds the data of an export file to a vehicle: the lease only when it has none, the readings and snapshots that are not
 * there yet. Nothing is replaced or deleted. See {@see VehicleDataImporter}.
 */
#[AsCommand(
    name: 'polaris:vehicle:data:import',
    description: 'Import the lease, readings and snapshots of an export file into a vehicle',
)]
final readonly class ImportVehicleDataCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private VehicleDataImporter $importer,
    ) {
    }

    /**
     * @throws ORMException
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The 17 characters VIN of the vehicle to import into')]
        string $vin,
        #[Argument(description: 'The export file (JSON)')]
        string $file,
    ): int {
        try {
            $vehicle = $this->entityManager->getRepository(Vehicle::class)->findOneBy([
                'vin' => new Vin($vin)->value(),
            ]);
        } catch (\InvalidArgumentException $invalid) {
            $io->error($invalid->getMessage());

            return Command::INVALID;
        }

        if (!$vehicle instanceof Vehicle) {
            $io->error('No vehicle with this VIN.');

            return Command::FAILURE;
        }

        $json = is_file($file) && is_readable($file) ? file_get_contents($file) : false;

        if (false === $json) {
            $io->error(\sprintf('The file %s cannot be read.', $file));

            return Command::FAILURE;
        }

        try {
            $result = $this->importer->import($vehicle, $json);
        } catch (VehicleDataRejected $rejected) {
            $io->error($rejected->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Lease' => match ($result->lease) {
                LeaseImportOutcome::Created => 'created',
                LeaseImportOutcome::Kept => 'kept (the vehicle already has one)',
                LeaseImportOutcome::None => 'none in the file',
            }],
            ['Readings' => \sprintf(
                '%d added, %d already there, %d refused',
                $result->readings->added,
                $result->readings->alreadyThere,
                $result->readings->rejected,
            )],
            ['Snapshots' => \sprintf(
                '%d added, %d already there, %d left out',
                $result->snapshots->added,
                $result->snapshots->alreadyThere,
                $result->snapshots->skipped,
            )],
        );
        $io->success(\sprintf('Imported into %s.', $vehicle->getName()));

        return Command::SUCCESS;
    }
}
