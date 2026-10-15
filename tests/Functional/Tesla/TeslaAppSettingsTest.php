<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Tesla;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Account\Entity\User;
use Polaris\Shared\Service\SecretEncrypter;
use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Service\TeslaAppSettings;
use Polaris\Tesla\Service\TeslaAppSettingsData;
use Polaris\Tesla\Service\TeslaSettingsRejected;
use Polaris\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(TeslaAppSettings::class)]
#[CoversClass(TeslaSettingsRejected::class)]
final class TeslaAppSettingsTest extends KernelTestCase
{
    use SignsIn;

    private const string SECRET = 'plain-client-secret';

    private TeslaAppSettings $settings;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->settings = self::getContainer()->get(TeslaAppSettings::class);
    }

    public function testItNeedsTheKeyPairFirst(): void
    {
        $owner = self::createUser();

        $this->expectException(TeslaSettingsRejected::class);
        $this->expectExceptionMessage('polaris:tesla:keygen');

        $this->settings->save($owner, $this->data());
    }

    public function testTheClientSecretIsEncryptedAtRest(): void
    {
        $owner = self::createUser();
        $this->createApp($owner);

        $this->settings->save($owner, $this->data());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $stored = $entityManager->getRepository(TeslaApp::class)->findOneBy(['owner' => $owner]);
        self::assertInstanceOf(TeslaApp::class, $stored);
        self::assertSame('client-id', $stored->getClientId());
        self::assertSame('polaris.example.com', $stored->getDomain()?->toString());
        self::assertSame([FleetRegion::EuropeMiddleEastAfrica], $stored->getEnabledRegions());
        self::assertTrue($stored->isConfigured());

        $ciphertext = (string) $stored->getEncryptedClientSecret();
        self::assertStringStartsWith('v1:', $ciphertext);
        self::assertStringNotContainsString(self::SECRET, $ciphertext);
        self::assertSame(self::SECRET, self::getContainer()->get(SecretEncrypter::class)->decrypt($ciphertext));

        $row = $entityManager->getConnection()->fetchOne('SELECT encrypted_client_secret FROM tesla_app');
        self::assertSame($ciphertext, $row);
    }

    public function testAnEmptySecretKeepsTheStoredOne(): void
    {
        $owner = self::createUser();
        $this->createApp($owner);
        $app = $this->settings->save($owner, $this->data());
        $ciphertext = $app->getEncryptedClientSecret();

        $app = $this->settings->save($owner, $this->data(secret: null, clientId: 'other-id'));

        self::assertSame($ciphertext, $app->getEncryptedClientSecret());
        self::assertSame('other-id', $app->getClientId());
    }

    public function testTheFirstSaveNeedsASecret(): void
    {
        $owner = self::createUser();
        $this->createApp($owner);

        $this->expectException(TeslaSettingsRejected::class);
        $this->expectExceptionMessage('client secret');

        $this->settings->save($owner, $this->data(secret: ''));
    }

    public function testChinaCannotBeEnabled(): void
    {
        $owner = self::createUser();
        $this->createApp($owner);

        try {
            $this->settings->save($owner, $this->data(regions: [FleetRegion::EuropeMiddleEastAfrica, FleetRegion::China]));
            self::fail('China must be refused.');
        } catch (TeslaSettingsRejected $rejected) {
            self::assertStringContainsString('China', $rejected->getMessage());
        }

        self::assertFalse($this->settings->find($owner)?->isConfigured());
    }

    public function testItRejectsAnInvalidDomainAndMissingValues(): void
    {
        $owner = self::createUser();
        $this->createApp($owner);

        foreach ([
            $this->data(domain: 'https://polaris.example.com'),
            $this->data(clientId: '  '),
            $this->data(regions: []),
        ] as $data) {
            try {
                $this->settings->save($owner, $data);
                self::fail('The settings must be refused.');
            } catch (TeslaSettingsRejected) {
                self::assertFalse($this->settings->find($owner)?->isConfigured());
            }
        }
    }

    public function testTheSecretStaysOutOfDumps(): void
    {
        self::assertStringNotContainsString(self::SECRET, print_r($this->data(), true));
    }

    /**
     * @param list<FleetRegion> $regions
     */
    private function data(
        ?string $secret = self::SECRET,
        string $clientId = 'client-id',
        string $domain = 'polaris.example.com',
        array $regions = [FleetRegion::EuropeMiddleEastAfrica],
    ): TeslaAppSettingsData {
        return new TeslaAppSettingsData($clientId, $secret, $domain, $regions);
    }

    private function createApp(User $owner): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new TeslaApp($owner, 'public key', 'v1:private', CarbonImmutable::parse('2026-10-20 12:00:00 UTC')));
        $entityManager->flush();
    }
}
