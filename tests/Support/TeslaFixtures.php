<?php

declare(strict_types=1);

namespace Polaris\Tests\Support;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Polaris\Account\Entity\User;
use Polaris\Shared\Service\SecretEncrypter;
use Polaris\Tesla\Domain\AppDomain;
use Polaris\Tesla\Domain\ConnectionMethod;
use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Entity\OAuthToken;
use Polaris\Tesla\Entity\TeslaApp;

/**
 * Persists a configured Tesla app and a stored connection, with obviously fake values.
 * Needs the same booted kernel as {@see SignsIn}.
 */
trait TeslaFixtures
{
    private static function createConfiguredApp(User $owner, bool $consent = true): TeslaApp
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $encrypter = self::getContainer()->get(SecretEncrypter::class);

        $app = new TeslaApp($owner, 'public key', 'v1:private', CarbonImmutable::parse('2026-10-20 12:00:00 UTC'));
        $app->configure('fake-client-id', $encrypter->encrypt('fake-client-secret'), AppDomain::fromString('polaris.example.com'), [FleetRegion::EuropeMiddleEastAfrica]);
        if ($consent) {
            $app->giveConsent(CarbonImmutable::parse('2026-10-24 09:00:00 UTC'));
        }
        $entityManager->persist($app);
        $entityManager->flush();

        return $app;
    }

    private static function createToken(TeslaApp $app, CarbonImmutable $expiresAt, string $access = 'stored-access', string $refresh = 'stored-refresh', string $scopes = 'openid offline_access vehicle_device_data'): OAuthToken
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $encrypter = self::getContainer()->get(SecretEncrypter::class);

        $token = new OAuthToken(
            $app,
            $encrypter->encrypt($access),
            $encrypter->encrypt($refresh),
            $expiresAt,
            $scopes,
            ConnectionMethod::OAuth,
            FleetRegion::EuropeMiddleEastAfrica->baseUrl(),
            CarbonImmutable::parse('2026-10-24 09:00:00 UTC'),
        );
        $entityManager->persist($token);
        $entityManager->flush();

        return $token;
    }
}
