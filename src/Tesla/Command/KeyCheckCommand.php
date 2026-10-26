<?php

declare(strict_types=1);

namespace Polaris\Tesla\Command;

use Polaris\Account\Entity\User;
use Polaris\Tesla\Service\PublicKeyChecker;
use Polaris\Tesla\Service\TeslaAppSettings;
use Polaris\Tesla\Service\TeslaOwnerFinder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Checks that Tesla can read the public key on the app domain. polaris:tesla:register runs the same
 * check first.
 */
#[AsCommand(name: 'polaris:tesla:key:check', description: 'Check that the public key is served on the app domain')]
final readonly class KeyCheckCommand
{
    public function __construct(
        private PublicKeyChecker $checker,
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

        $result = $this->checker->check($app);

        if (!$result->isMatch()) {
            $io->error($result->message);

            return Command::FAILURE;
        }

        $io->success($result->message);

        return Command::SUCCESS;
    }
}
