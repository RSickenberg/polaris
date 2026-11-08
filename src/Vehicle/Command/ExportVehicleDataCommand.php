<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Polaris\Vehicle\Service\VehicleDataExporter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Writes the lease, the odometer readings and the daily snapshots of a vehicle as JSON, to a file or to the output (so it
 * can be piped). The file holds no VIN and no Tesla token. See {@see VehicleDataExporter}.
 */
#[AsCommand(
    name: 'polaris:vehicle:data:export',
    description: 'Export the lease, readings and snapshots of a vehicle as JSON',
)]
final readonly class ExportVehicleDataCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private VehicleDataExporter $exporter,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @throws \JsonException
     */
    public function __invoke(
        SymfonyStyle $io,
        OutputInterface $output,
        #[Argument(description: 'The 17 characters VIN of the vehicle')]
        string $vin,
        #[Option(description: 'The file to write. Omit it to print the JSON')]
        ?string $file = null,
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

        $json = $this->exporter->export($vehicle);

        if (null === $file) {
            $output->writeln($json, OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        try {
            $this->filesystem->dumpFile($file, $json . "\n");
        } catch (IOException $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Exported %s to %s.', $vehicle->getName(), $file));

        return Command::SUCCESS;
    }
}
