<?php

declare(strict_types=1);

namespace Polaris\Tesla\Command;

use Polaris\Account\Entity\User;
use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Service\TeslaAppSettings;
use Polaris\Tesla\Service\TeslaAppSettingsData;
use Polaris\Tesla\Service\TeslaOwnerFinder;
use Polaris\Tesla\Service\TeslaSettingsRejected;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Stores the credentials of the Tesla app. Like the password of polaris:user:create, the client
 * secret is never an argument or an option (shell history, process list): it is asked with a hidden
 * prompt, or read from the standard input when the command is not interactive. Options left out keep
 * the stored value.
 */
#[AsCommand(name: 'polaris:tesla:configure', description: 'Store the client ID, client secret, domain and regions of the Tesla app')]
final readonly class ConfigureCommand
{
    public function __construct(
        private TeslaAppSettings $settings,
        private TeslaOwnerFinder $owners,
    ) {
    }

    /**
     * @param list<string> $region
     */
    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Option(description: 'The client ID of the Tesla developer app', name: 'client-id')]
        ?string $clientId = null,
        #[Option(description: 'The domain that hosts the public key, for example polaris.example.com')]
        ?string $domain = null,
        #[Option(description: 'A Fleet API region to enable: na (North America and Asia-Pacific) or eu (Europe, Middle East, Africa). Repeat for several')]
        array $region = [],
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
            $io->error(TeslaSettingsRejected::keyPairMissing()->getMessage());

            return Command::FAILURE;
        }

        $regions = [];
        foreach ($region as $code) {
            $parsed = $code
                    |> trim(...)
                    |> strtolower(...)
                    |> FleetRegion::tryFrom(...);

            if (null === $parsed) {
                $io->error(\sprintf('Unknown region "%s". Use na or eu.', $code));

                return Command::INVALID;
            }

            $regions[] = $parsed;
        }

        $secret = $this->readSecret($io, $input, null !== $app->getEncryptedClientSecret());

        try {
            $app = $this->settings->save($owner, new TeslaAppSettingsData(
                $clientId ?? $app->getClientId() ?? '',
                $secret,
                $domain ?? $app->getDomain()?->toString() ?? '',
                [] === $regions ? $app->getEnabledRegions() : $regions,
            ));
        } catch (TeslaSettingsRejected $rejected) {
            $io->error($rejected->getMessage());

            return Command::FAILURE;
        }

        $io->success('Stored the Tesla app settings. The client secret is encrypted and never shown.');
        $io->definitionList(
            ['Client ID' => $app->getClientId()],
            ['Domain' => $app->getDomain()?->toString()],
            ['Regions' => implode(', ', array_map(static fn (FleetRegion $enabled): string => $enabled->value, $app->getEnabledRegions()))],
        );

        return Command::SUCCESS;
    }

    private function readSecret(SymfonyStyle $io, InputInterface $input, bool $hasStoredSecret): ?string
    {
        if (!$input->isInteractive()) {
            $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
            $line = fgets($stream ?? \STDIN);

            return false === $line || '' === rtrim($line, "\r\n") ? null : rtrim($line, "\r\n");
        }

        $question = new Question($hasStoredSecret ? 'Client secret (empty keeps the stored one)' : 'Client secret');
        $question->setHidden(true);
        $secret = $io->askQuestion($question);

        return \is_string($secret) && '' !== $secret ? $secret : null;
    }
}
