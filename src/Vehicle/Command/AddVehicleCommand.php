<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Account\Entity\User;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Adds a vehicle to a user. The VIN is the one the Tesla Fleet API lists for the account
 * (`polaris:tesla:debug:fetch list`).
 */
#[AsCommand(name: 'polaris:vehicle:add', description: 'Add a vehicle by its VIN')]
final readonly class AddVehicleCommand
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @throws ORMException
     */
    public function __invoke(
        SymfonyStyle $io,

        #[Argument(description: 'The 17 characters VIN')]
        string $vin,

        #[Argument(description: 'A name to recognise the vehicle')]
        string $name,

        #[Option(description: 'The email of the owner. Optional when there is a single user')]
        ?string $email = null,
    ): int {
        $users = $this->entityManager->getRepository(User::class);
        $owner = null === $email ? null : $users->findOneBy(['email' => User::normalizeEmail($email)]);

        if (null === $email) {
            $all = $users->findBy([], null, 2);
            $owner = 1 === \count($all) ? $all[0] : null;
        }

        if (!$owner instanceof User) {
            $io->error('No user found. Create one with polaris:user:create, or pass --email.');

            return Command::FAILURE;
        }

        try {
            $vehicle = new Vehicle(new Vin($vin), $name, $owner);
        } catch (\InvalidArgumentException $invalid) {
            $io->error($invalid->getMessage());

            return Command::INVALID;
        }

        if (null !== $this->entityManager->getRepository(Vehicle::class)->findOneBy(['vin' => $vehicle->getVin()->value()])) {
            $io->error('A vehicle with this VIN already exists.');

            return Command::FAILURE;
        }

        $this->entityManager->persist($vehicle);
        $this->entityManager->flush();

        $io->success(\sprintf('Added %s (%s). It reads once a day at 21:00 by default: see polaris:fetch:schedule.', $vehicle->getName(), $vehicle->getVin()->value()));

        return Command::SUCCESS;
    }
}
