<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Command;

use Doctrine\ORM\Exception\ORMException;
use Polaris\Account\Entity\User;
use Polaris\Tesla\Service\Exception\FleetApiError;
use Polaris\Tesla\Service\Exception\TeslaNotConnected;
use Polaris\Tesla\Service\TeslaOwnerFinder;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Service\TeslaVehicleImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Adds the vehicles of the Tesla account that Polaris does not have yet. It only lists the account (no car is
 * contacted, so no car wakes up).
 */
#[AsCommand(name: 'polaris:vehicle:import', description: 'Import the vehicles of your Tesla account')]
final readonly class ImportVehiclesCommand
{
    public function __construct(
        private TeslaVehicleImporter $importer,
        private TeslaOwnerFinder $owners,
    ) {
    }

    /**
     * @param list<string> $vin
     *
     * @throws ORMException
     */
    public function __invoke(
        SymfonyStyle $io,

        #[Option(description: 'Only import this VIN (repeat the option for several). Default: every new vehicle')]
        array $vin = [],

        #[Option(description: 'List what would be imported, add nothing')]
        bool $dryRun = false,

        #[Option(description: 'The email of the owner. Optional when there is a single user')]
        ?string $email = null,
    ): int {
        $owner = $this->owners->find($email);

        if (!$owner instanceof User) {
            $io->error('No user found. Create one with polaris:user:create, or pass --email.');

            return Command::FAILURE;
        }

        try {
            $only = [] === $vin ? null : array_map(static fn (string $value): Vin => new Vin($value), $vin);

            if ($dryRun) {
                $rows = [];
                foreach ($this->importer->candidates($owner) as $candidate) {
                    $rows[] = [$candidate->summary->vin->value(), $candidate->name(), $candidate->summary->state->value, $candidate->added ? 'already added' : 'would be added'];
                }
                $io->table(['VIN', 'Name', 'State', 'Result'], $rows);

                return Command::SUCCESS;
            }

            $result = $this->importer->import($owner, $only);
        } catch (\InvalidArgumentException $invalid) {
            $io->error($invalid->getMessage());

            return Command::INVALID;
        } catch (TeslaNotConnected $notConnected) {
            $io->error($notConnected->getMessage() . ' Connect Tesla first (see the README).');

            return Command::FAILURE;
        } catch (FleetApiError $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }

        foreach ($result->added as $vehicle) {
            $io->writeln(\sprintf('Added %s (%s).', $vehicle->getName(), $vehicle->getVin()->value()));
        }
        $io->success(\sprintf('%d added, %d already there.', \count($result->added), $result->alreadyAdded));

        return Command::SUCCESS;
    }
}
