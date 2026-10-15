<?php

declare(strict_types=1);

namespace Polaris\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use Polaris\Account\Entity\User;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Gives the development user that `make seed` loaded a random password and prints it once.
 * Nothing is committed: the password exists only in this output.
 */
#[AsCommand(name: 'polaris:dev:user', description: 'Set a random password on the development user and print it once')]
final class SeedUserCommand extends Command
{
    public const string EMAIL = 'dev@example.test';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);

        if (!$user instanceof User) {
            $io->error('The development user does not exist: run make seed.');

            return Command::FAILURE;
        }

        $password = bin2hex(random_bytes(12));
        $user->changePassword($this->hasher->hashPassword($user, $password));
        $this->entityManager->flush();

        $io->success('The development user is ready. The password is shown once, note it now.');
        $io->definitionList(['Email' => self::EMAIL], ['Password' => $password]);

        return Command::SUCCESS;
    }
}
