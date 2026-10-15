<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service;

use Polaris\Shared\Service\DecryptionFailed;
use Polaris\Shared\Service\SecretEncrypter;
use Polaris\Tesla\Domain\PublicKeyFormat;
use Polaris\Tesla\Entity\TeslaApp;

/**
 * Registers the app with the Fleet API, once per enabled region (ADR 0004). Tesla must be able to
 * read the public key on the app domain, so the key check runs first and nothing is sent to Tesla
 * when it fails. Each region is independent: a failure in one does not stop the others.
 */
final readonly class PartnerRegistrationService
{
    public function __construct(
        private PublicKeyChecker $keyChecker,
        private FleetApiClient $api,
        private SecretEncrypter $encrypter,
        private PublicKeyFormat $keyFormat,
    ) {
    }

    public function register(TeslaApp $app): RegistrationReport
    {
        $domain = $app->getDomain();
        $clientId = $app->getClientId();
        $encryptedSecret = $app->getEncryptedClientSecret();

        if (null === $domain || null === $clientId || null === $encryptedSecret || [] === $app->getEnabledRegions()) {
            return RegistrationReport::blocked('The app is not configured yet. Store the client ID, client secret, domain and regions with polaris:tesla:configure.');
        }

        $keyCheck = $this->keyChecker->check($app);

        if (!$keyCheck->isMatch()) {
            return RegistrationReport::blocked('Tesla cannot read the public key, so the app was not registered. ' . $keyCheck->message, $keyCheck);
        }

        try {
            $clientSecret = $this->encrypter->decrypt($encryptedSecret);
        } catch (DecryptionFailed|\SodiumException $failure) {
            return RegistrationReport::blocked('The stored client secret cannot be decrypted: ' . $failure->getMessage() . ' Store it again with polaris:tesla:configure.', $keyCheck);
        }

        $results = [];

        foreach ($app->getEnabledRegions() as $region) {
            if (!$region->isSupported()) {
                $results[] = new RegionRegistrationResult(
                    $region,
                    RegistrationStatus::Refused,
                    'This region is not supported: it needs a separate Tesla developer app, which Polaris does not handle yet.',
                );

                continue;
            }

            try {
                $token = $this->api->partnerToken($region, $clientId, $clientSecret);
                $this->api->registerPartner($region, $token, $domain);
                $registered = $this->api->registeredPublicKey($region, $token, $domain);
            } catch (FleetApiError $error) {
                $results[] = new RegionRegistrationResult($region, RegistrationStatus::Failed, $error->getMessage());

                continue;
            }

            $results[] = $this->keyFormat->sameKey($app->getPublicKey(), $registered)
                ? new RegionRegistrationResult($region, RegistrationStatus::Registered, 'Registered, and Tesla returns the stored public key.')
                : new RegionRegistrationResult($region, RegistrationStatus::Failed, 'Registered, but the public key Tesla returns for the domain is not the stored one.');
        }

        return RegistrationReport::completed($keyCheck, $results);
    }
}
