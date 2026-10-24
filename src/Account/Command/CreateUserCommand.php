<?php

declare(strict_types=1);

namespace Polaris\Account\Command;

use Polaris\Account\Service\Dto\NewUser;
use Polaris\Account\Service\Exception\UserCreationRejected;
use Polaris\Account\Service\UserCreator;
use Polaris\Shared\Domain\TimeZone;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates the one user of a self-hosted install. The password is never an argument or an option
 * (shell history, process list): it is asked with a hidden prompt, or read from the standard
 * input when the command is not interactive.
 */
#[AsCommand(name: 'polaris:user:create', description: 'Create the user who signs in to Polaris')]
final readonly class CreateUserCommand
{
    public function __construct(private UserCreator $creator)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Argument(description: 'The email address the user signs in with')]
        string $email,
        #[Option(description: 'The preferred language: en or fr. Defaults to en')]
        ?string $locale = null,
        #[Option(name: 'time-zone', description: 'The IANA time zone dates are shown in, for example Europe/Zurich')]
        string $timeZone = TimeZone::UTC,
    ): int {
        $password = $this->readPassword($io, $input);
        if (null === $password) {
            return Command::INVALID;
        }

        try {
            $user = $this->creator->create(new NewUser(trim($email), $password, $locale, $timeZone));
        } catch (UserCreationRejected $rejected) {
            $io->error($rejected->getMessage());

            return Command::FAILURE;
        } catch (\InvalidArgumentException $invalid) {
            $io->error($invalid->getMessage());

            return Command::INVALID;
        }

        $io->success(\sprintf('Created the user %s. Sign in at /login.', $user->getEmail()));

        return Command::SUCCESS;
    }

    private function readPassword(SymfonyStyle $io, InputInterface $input): ?string
    {
        if (!$input->isInteractive()) {
            $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
            $line = fgets($stream ?? \STDIN);

            if (false === $line || '' === rtrim($line, "\r\n")) {
                $io->error('Not interactive: pipe the password on the standard input.');

                return null;
            }

            return rtrim($line, "\r\n");
        }

        $question = new Question('Password');
        $question->setHidden(true);
        $question->setMaxAttempts(3);
        $password = $io->askQuestion($question);

        $confirmation = new Question('Confirm the password');
        $confirmation->setHidden(true);
        if ($password !== $io->askQuestion($confirmation)) {
            $io->error('The two passwords do not match.');

            return null;
        }

        return \is_string($password) ? $password : null;
    }
}
