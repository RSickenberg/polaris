<?php

declare(strict_types=1);

namespace Polaris\Fixtures;

use Polaris\Account\Service\NewUser;
use Polaris\Account\Service\UserCreationRejected;
use Polaris\Account\Service\UserCreator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates the development user after `make seed` loaded the sample data, with a random password
 * that is shown once. Nothing is committed: the password exists only in this output.
 */
#[AsCommand(name: 'polaris:dev:user', description: 'Create the development user with a random password and print it once')]
final class SeedUserCommand extends Command
{
    public const string EMAIL = 'dev@example.test';

    public function __construct(private readonly UserCreator $creator)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $password = bin2hex(random_bytes(12));

        try {
            $this->creator->create(new NewUser(self::EMAIL, $password));
        } catch (UserCreationRejected $rejected) {
            $io->error($rejected->getMessage());

            return Command::FAILURE;
        }

        $io->success('Created the development user. The password is shown once, note it now.');
        $io->definitionList(['Email' => self::EMAIL], ['Password' => $password]);

        return Command::SUCCESS;
    }
}
