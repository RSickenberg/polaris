<?php

declare(strict_types=1);

namespace Polaris\Tesla\Command;

use Polaris\Account\Entity\User;
use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Service\Dto\TeslaAppSettingsData;
use Polaris\Tesla\Service\Exception\TeslaSettingsRejected;
use Polaris\Tesla\Service\TeslaAppSettings;
use Polaris\Tesla\Service\TeslaOwnerFinder;
use Random\RandomException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Stores the credentials of the Tesla app. Like the password of polaris:user:create, the client
 * secret is never an argument or an option (shell history, process list): it is asked with a hidden
 * prompt, or read from the standard input when the command is not interactive. Options left out keep
 * the stored value. The client ID, domain and regions are asked on a terminal when their option is missing.
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

        $terminal = $this->isTerminal($input);

        if ([] === $region && $terminal) {
            $region = $this->askRegions($io, $app->getEnabledRegions());
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

        if (null === $clientId && $terminal) {
            $clientId = $this->askClientId($io, $app->getClientId());
        }

        if (null === $domain && $terminal) {
            $domain = $this->askDomain($io, $app->getDomain()?->toString());
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
        } catch (RandomException $e) {
            $io->error('Random error: ' . $e->getMessage());

            return Command::FAILURE;
        } catch (\SodiumException $e) {
            $io->outlineError('Sodium error: ' . $e->getMessage());

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

    private function askClientId(SymfonyStyle $io, ?string $stored): ?string
    {
        $answer = $io->ask(
            null === $stored ? 'Client ID' : 'Client ID (empty keeps the stored one)',
            $stored,
        );

        return \is_string($answer) && '' !== trim($answer) ? trim($answer) : null;
    }

    private function askDomain(SymfonyStyle $io, ?string $stored): ?string
    {
        $answer = $io->ask(
            null === $stored ? 'Domain that hosts the public key' : 'Domain that hosts the public key (empty keeps the stored one)',
            $stored,
        );

        return \is_string($answer) && '' !== trim($answer) ? trim($answer) : null;
    }

    /**
     * @param list<FleetRegion> $stored
     *
     * @return list<string>
     */
    private function askRegions(SymfonyStyle $io, array $stored): array
    {
        $choices = [];
        foreach (FleetRegion::cases() as $case) {
            if ($case->isSupported()) {
                $choices[] = $case->value;
            }
        }

        $question = new ChoiceQuestion(
            'Regions to enable (comma separated, empty keeps the stored ones)',
            $choices,
            [] === $stored ? null : implode(',', array_map(static fn (FleetRegion $region): string => $region->value, $stored)),
        );
        $question->setMultiselect(true);

        $answer = $io->askQuestion($question);

        if (!\is_array($answer)) {
            return [];
        }

        return array_values(array_filter($answer, \is_string(...)));
    }

    private function isTerminal(InputInterface $input): bool
    {
        $stream = ($input instanceof StreamableInputInterface ? $input->getStream() : null) ?? \STDIN;

        return $input->isInteractive() && stream_isatty($stream);
    }

    private function readSecret(SymfonyStyle $io, InputInterface $input, bool $hasStoredSecret): ?string
    {
        // Symfony 8 does not turn interactivity off for a pipe: a secret piped on the standard input is
        // read from it, a terminal gets the hidden prompt.
        $stream = ($input instanceof StreamableInputInterface ? $input->getStream() : null) ?? \STDIN;

        if (!$input->isInteractive() || !stream_isatty($stream)) {
            $line = fgets($stream);

            return false === $line || '' === rtrim($line, "\r\n") ? null : rtrim($line, "\r\n");
        }

        $question = new Question($hasStoredSecret ? 'Client secret (empty keeps the stored one)' : 'Client secret');
        $question->setHidden(true);
        $secret = $io->askQuestion($question);

        return \is_string($secret) && '' !== $secret ? $secret : null;
    }
}
