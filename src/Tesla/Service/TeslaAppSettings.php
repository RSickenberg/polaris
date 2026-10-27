<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Account\Entity\User;
use Polaris\Shared\Service\SecretEncrypter;
use Polaris\Tesla\Domain\AppDomain;
use Polaris\Tesla\Domain\InvalidAppDomain;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Service\Dto\TeslaAppSettingsData;
use Polaris\Tesla\Service\Exception\TeslaSettingsRejected;
use Random\RandomException;

/**
 * Stores the credentials of the user's Tesla app. The client secret is encrypted before it reaches
 * the entity (ADR 0007). The app row is created by the key generation, so the key pair comes first.
 */
final readonly class TeslaAppSettings
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SecretEncrypter $encrypter,
    ) {
    }

    public function find(User $owner): ?TeslaApp
    {
        return $this->entityManager->getRepository(TeslaApp::class)->findOneBy(['owner' => $owner]);
    }

    /**
     * @throws RandomException
     * @throws \SodiumException
     * @throws TeslaSettingsRejected
     * @throws ORMException
     */
    public function save(User $owner, TeslaAppSettingsData $data): TeslaApp
    {
        $app = $this->find($owner) ?? throw TeslaSettingsRejected::keyPairMissing();

        $clientId = trim($data->clientId);
        if ('' === $clientId) {
            throw TeslaSettingsRejected::missing('client ID');
        }

        try {
            $domain = AppDomain::fromString($data->domain);
        } catch (InvalidAppDomain $invalid) {
            throw new TeslaSettingsRejected($invalid->getMessage(), 0, $invalid);
        }

        if ([] === $data->regions) {
            throw TeslaSettingsRejected::missing('region');
        }

        foreach ($data->regions as $region) {
            if (!$region->isSupported()) {
                throw TeslaSettingsRejected::regionNotSupported($region);
            }
        }

        if ($data->dailyWakeCap < 0 || $data->dailyWakeCap > TeslaApp::MAX_DAILY_WAKE_CAP) {
            throw TeslaSettingsRejected::wakeCapOutOfRange(TeslaApp::MAX_DAILY_WAKE_CAP);
        }

        $encryptedSecret = '' === ($data->clientSecret ?? '')
            ? $app->getEncryptedClientSecret()
            : $this->encrypter->encrypt((string) $data->clientSecret);

        if (null === $encryptedSecret) {
            throw TeslaSettingsRejected::missing('client secret');
        }

        $app->configure($clientId, $encryptedSecret, $domain, $data->regions);
        $app->changeDailyWakeCap($data->dailyWakeCap);
        $this->entityManager->flush();

        return $app;
    }
}
