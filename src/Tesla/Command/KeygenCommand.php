<?php

declare(strict_types=1);

namespace Polaris\Tesla\Command;

use Doctrine\ORM\EntityManagerInterface;
use Polaris\Account\Entity\User;
use Polaris\Extension\PublicKeyHosting;
use Polaris\Tesla\Service\KeyAlreadyExists;
use Polaris\Tesla\Service\TeslaKeyService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generates the Tesla key pair. It prints the public key only; the private key is stored encrypted
 * and never shown.
 */
#[AsCommand(name: 'polaris:tesla:keygen', description: 'Generate the Tesla key pair (secp256r1) and publish the public key')]
final readonly class KeygenCommand
{
    public function __construct(
        private TeslaKeyService $keys,
        private PublicKeyHosting $hosting,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Replace an existing key pair. The app must then be registered with Tesla again')]
        bool $force = false,
        #[Option(description: 'The email of the owner. Optional when there is a single user')]
        ?string $email = null,
    ): int {
        $owner = $this->findOwner($email);

        if (!$owner instanceof User) {
            $io->error('No user found. Create one with polaris:user:create, or pass --email.');

            return Command::FAILURE;
        }

        try {
            $app = $this->keys->generate($owner, $force);
        } catch (KeyAlreadyExists $exists) {
            $io->error($exists->getMessage());

            return Command::FAILURE;
        } catch (\RuntimeException $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $io->success('Generated a prime256v1 key pair. The private key is stored encrypted and is never shown.');
        $io->writeln($app->getPublicKey());
        $io->note(
            $this->hosting->servesPublicKey()
                ? 'Polaris serves the public key at /.well-known/appspecific/com.tesla.3p.public-key.pem on your domain.'
                : 'The public key was written to the static site directory. Commit and push it yourself.',
        );

        return Command::SUCCESS;
    }

    private function findOwner(?string $email): ?User
    {
        $users = $this->entityManager->getRepository(User::class);

        if (null !== $email) {
            return $users->findOneBy(['email' => User::normalizeEmail($email)]);
        }

        $all = $users->findBy([], null, 2);

        return 1 === \count($all) ? $all[0] : null;
    }
}
