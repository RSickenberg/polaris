<?php

declare(strict_types=1);

namespace Polaris\Lease\Command;

use Doctrine\ORM\EntityManagerInterface;
use Polaris\Lease\Repository\LeaseContractRepository;
use Polaris\Lease\Service\Enum\SnapshotOutcome;
use Polaris\Lease\Service\ProjectionSnapshotService;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Saves today's projection snapshot of one lease or of all of them, as the daily schedule does. Running it again the
 * same day replaces the figures of the day, so it never adds a second row.
 */
#[AsCommand(name: 'polaris:lease:snapshot', description: 'Save today\'s projection snapshot of one lease (--vin) or of all leases')]
final readonly class SnapshotCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LeaseContractRepository $contracts,
        private ProjectionSnapshotService $snapshots,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Option(description: 'The VIN of the vehicle. Omit it to snapshot every lease')]
        ?string $vin = null,
    ): int {
        if (null !== $vin) {
            $vehicle = $this->entityManager->getRepository(Vehicle::class)->findOneBy(['vin' => strtoupper(trim($vin))]);

            if (!$vehicle instanceof Vehicle) {
                $io->error(\sprintf('No vehicle with VIN %s.', strtoupper(trim($vin))));

                return Command::FAILURE;
            }

            $vehicles = [$vehicle];
        } else {
            $vehicles = array_map(static fn ($contract): Vehicle => $contract->getVehicle(), $this->contracts->findAll());
        }

        /** @var array<string, int> $counts */
        $counts = [];

        foreach ($vehicles as $vehicle) {
            $outcome = $this->snapshots->save($vehicle);
            $counts[$outcome->value] = ($counts[$outcome->value] ?? 0) + 1;
        }

        $saved = $counts[SnapshotOutcome::Saved->value] ?? 0;
        $updated = $counts[SnapshotOutcome::Updated->value] ?? 0;
        $skipped = array_diff_key($counts, [SnapshotOutcome::Saved->value => 1, SnapshotOutcome::Updated->value => 1]);
        $reasons = [];

        foreach ($skipped as $reason => $count) {
            $reasons[] = \sprintf('%d %s', $count, $reason);
        }

        $io->success(\sprintf(
            '%d saved, %d already there (updated), %d skipped%s.',
            $saved,
            $updated,
            array_sum($skipped),
            [] === $reasons ? '' : ' (' . implode(', ', $reasons) . ')',
        ));

        return Command::SUCCESS;
    }
}
