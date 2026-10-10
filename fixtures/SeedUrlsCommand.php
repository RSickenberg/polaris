<?php

declare(strict_types=1);

namespace Polaris\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Lists the pages of every vehicle, to open them after `make seed`.
 */
#[AsCommand(name: 'polaris:dev:urls', description: 'List the page URLs of every vehicle')]
final class SeedUrlsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = [];
        foreach ($this->entityManager->getRepository(Vehicle::class)->findBy([], ['name' => 'ASC']) as $vehicle) {
            $parameters = ['id' => $vehicle->getId()->toBase32()];
            $rows[] = [
                $vehicle->getName(),
                $this->urlGenerator->generate('reading_history', $parameters, UrlGeneratorInterface::ABSOLUTE_URL),
                $this->urlGenerator->generate('lease_settings', $parameters, UrlGeneratorInterface::ABSOLUTE_URL),
            ];
        }

        $io->table(['Vehicle', 'Readings', 'Lease terms'], $rows);

        return Command::SUCCESS;
    }
}
