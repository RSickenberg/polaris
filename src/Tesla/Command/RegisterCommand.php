<?php

declare(strict_types=1);

namespace Polaris\Tesla\Command;

use Polaris\Account\Entity\User;
use Polaris\Tesla\Service\Enum\RegistrationStatus;
use Polaris\Tesla\Service\PartnerRegistrationService;
use Polaris\Tesla\Service\TeslaAppSettings;
use Polaris\Tesla\Service\TeslaOwnerFinder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Registers the Tesla app in every enabled Fleet API region, after checking that the public key is
 * served on the app domain.
 */
#[AsCommand(name: 'polaris:tesla:register', description: 'Register the Tesla app with the Fleet API in each enabled region')]
final readonly class RegisterCommand
{
    public function __construct(
        private PartnerRegistrationService $registration,
        private TeslaAppSettings $settings,
        private TeslaOwnerFinder $owners,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Option(description: 'The email of the owner. Optional when there is a single user')]
        ?string $email = null,
    ): int {
        $owner = $this->owners->find($email);

        if (!$owner instanceof User) {
            $io->error('No user found. Create one with polaris:user:create, or pass --email.');

            return Command::FAILURE;
        }

        $app = $this->settings->find($owner);

        if (null === $app) {
            $io->error('There is no Tesla key pair yet. Generate it first with polaris:tesla:keygen.');

            return Command::FAILURE;
        }

        $report = $this->registration->register($app);

        if (null !== $report->keyCheck && $report->keyCheck->isMatch()) {
            $io->writeln($report->keyCheck->message);
        }

        if (null !== $report->blocker) {
            $io->error($report->blocker);

            return Command::FAILURE;
        }

        foreach ($report->results as $result) {
            $line = \sprintf('[%s] %s', $result->region->value, $result->message);

            RegistrationStatus::Registered === $result->status ? $io->writeln($line) : $io->getErrorStyle()->writeln('<error>' . $line . '</error>');
        }

        if (!$report->isSuccess()) {
            $io->error('The app is not registered in every enabled region. Fix the errors above and run the command again.');

            return Command::FAILURE;
        }

        $io->success('The app is registered in every enabled region.');

        return Command::SUCCESS;
    }
}
