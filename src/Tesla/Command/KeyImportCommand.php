<?php

declare(strict_types=1);

namespace Polaris\Tesla\Command;

use Doctrine\ORM\Exception\ORMException;
use Polaris\Account\Entity\User;
use Polaris\Extension\PublicKeyHosting;
use Polaris\Tesla\Domain\InvalidKeyPair;
use Polaris\Tesla\Service\Exception\KeyAlreadyExists;
use Polaris\Tesla\Service\TeslaKeyService;
use Polaris\Tesla\Service\TeslaOwnerFinder;
use Random\RandomException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Keeps a key pair that already exists, instead of generating one: the private key PEM is read from
 * a file or from the standard input (never an argument), the public key is derived from it, and the
 * private key is stored encrypted and never shown.
 */
#[AsCommand(name: 'polaris:tesla:key:import', description: 'Use an existing prime256v1 private key instead of generating a key pair')]
final readonly class KeyImportCommand
{
    public function __construct(
        private TeslaKeyService $keys,
        private PublicKeyHosting $hosting,
        private TeslaOwnerFinder $owners,
    ) {
    }

    /**
     * @throws ORMException
     * @throws RandomException
     * @throws \SodiumException
     */
    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Option(description: 'Path of the private key PEM file, as seen by the process. Without it the key is read from the standard input')]
        ?string $file = null,
        #[Option(description: 'Replace an existing key pair. The app must then be registered with Tesla again')]
        bool $force = false,
        #[Option(description: 'The email of the owner. Optional when there is a single user')]
        ?string $email = null,
    ): int {
        $owner = $this->owners->find($email);

        if (!$owner instanceof User) {
            $io->error('No user found. Create one with polaris:user:create, or pass --email.');

            return Command::FAILURE;
        }

        $pem = $this->readPrivateKey($input, $file);

        if (null === $pem) {
            $io->error(null === $file
                ? 'No private key received. Pipe the PEM file on the standard input, or pass --file.'
                : 'The file cannot be read.');

            return Command::INVALID;
        }

        try {
            $app = $this->keys->import($owner, $pem, $force);
        } catch (KeyAlreadyExists $exists) {
            $io->error($exists->getMessage());

            return Command::FAILURE;
        } catch (InvalidKeyPair $invalid) {
            $io->error($invalid->getMessage());

            return Command::INVALID;
        } catch (\RuntimeException $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $io->success('Imported the key pair. The private key is stored encrypted and is never shown.');
        $io->writeln($app->getPublicKey());
        $io->note(\sprintf(
            'Check that this is the public key served at %s with polaris:tesla:key:check.',
            $this->hosting->publicKeyUrl() ?? '/.well-known/appspecific/com.tesla.3p.public-key.pem on your domain',
        ));

        return Command::SUCCESS;
    }

    private function readPrivateKey(InputInterface $input, ?string $file): ?string
    {
        if (null !== $file) {
            $content = is_file($file) && is_readable($file) ? file_get_contents($file) : false;

            return false === $content || '' === trim($content) ? null : $content;
        }

        // Symfony 8 does not turn interactivity off for a pipe: look at the input itself. A terminal has
        // nothing to read, a redirected file or a pipe does.
        $stream = ($input instanceof StreamableInputInterface ? $input->getStream() : null) ?? \STDIN;

        if (stream_isatty($stream)) {
            return null;
        }

        $content = stream_get_contents($stream);

        return false === $content || '' === trim($content) ? null : $content;
    }
}
