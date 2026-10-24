<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Polaris\Account\Entity\User;
use Polaris\Extension\PublicKeyHosting;
use Polaris\Shared\Service\SecretEncrypter;
use Polaris\Tesla\Domain\InvalidKeyPair;
use Polaris\Tesla\Domain\KeyPair;
use Polaris\Tesla\Domain\KeyPairGenerator;
use Polaris\Tesla\Domain\KeyPairImporter;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Service\Exception\KeyAlreadyExists;
use Psr\Clock\ClockInterface;

/**
 * Creates the key pair of a user's Tesla app: the private key is stored encrypted, the public key
 * in clear and handed to the key hosting.
 */
final readonly class TeslaKeyService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private KeyPairGenerator $generator,
        private KeyPairImporter $importer,
        private SecretEncrypter $encrypter,
        private PublicKeyHosting $hosting,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws KeyAlreadyExists  when the user already has a key pair and $force is false
     * @throws \RuntimeException when the key cannot be generated or published; nothing is kept
     */
    public function generate(User $owner, bool $force = false): TeslaApp
    {
        $this->assertCanStore($owner, $force);

        return $this->store($owner, $this->generator->generate());
    }

    /**
     * Keeps an existing key pair, for an app whose public key is already published and registered.
     *
     * @throws KeyAlreadyExists  when the user already has a key pair and $force is false
     * @throws InvalidKeyPair    when the private key is not a usable prime256v1 key
     * @throws \RuntimeException when the key cannot be published; nothing is kept
     */
    public function import(User $owner, #[\SensitiveParameter] string $privateKeyPem, bool $force = false): TeslaApp
    {
        $this->assertCanStore($owner, $force);

        return $this->store($owner, $this->importer->fromPrivateKey($privateKeyPem));
    }

    private function assertCanStore(User $owner, bool $force): void
    {
        if (!$force && $this->entityManager->getRepository(TeslaApp::class)->findOneBy(['owner' => $owner]) instanceof TeslaApp) {
            throw KeyAlreadyExists::forApp();
        }
    }

    private function store(User $owner, KeyPair $keyPair): TeslaApp
    {
        $app = $this->entityManager->getRepository(TeslaApp::class)->findOneBy(['owner' => $owner]);
        $encryptedPrivateKey = $this->encrypter->encrypt($keyPair->privateKeyPem());
        $now = CarbonImmutable::instance($this->clock->now());

        // The hosting runs inside the transaction: if the public key cannot be published, the new
        // key is not kept.
        $this->entityManager->wrapInTransaction(function () use ($app, $owner, $keyPair, $encryptedPrivateKey, $now): void {
            if ($app instanceof TeslaApp) {
                $app->replaceKeyPair($keyPair->publicKeyPem, $encryptedPrivateKey, $now);
            } else {
                $this->entityManager->persist(new TeslaApp($owner, $keyPair->publicKeyPem, $encryptedPrivateKey, $now));
            }

            $this->entityManager->flush();
            $this->hosting->publish($keyPair->publicKeyPem);
        });

        return $app ?? $this->entityManager->getRepository(TeslaApp::class)->findOneBy(['owner' => $owner]) ?? throw new \LogicException('The Tesla app was not saved.');
    }
}
