<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Tesla;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Account\Entity\User;
use Polaris\Shared\Service\SecretEncrypter;
use Polaris\Tesla\Controller\TeslaSettingsController;
use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Form\Model\TeslaAppSettingsFormData;
use Polaris\Tesla\Form\TeslaAppSettingsType;
use Polaris\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversClass(TeslaSettingsController::class)]
#[CoversClass(TeslaAppSettingsType::class)]
#[CoversClass(TeslaAppSettingsFormData::class)]
final class TeslaSettingsControllerTest extends WebTestCase
{
    use SignsIn;

    private const string SECRET = 'plain-client-secret';
    private const string FORM = 'tesla_app_settings';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testWithoutAKeyPairThePageSaysToGenerateOne(): void
    {
        self::signIn($this->client);

        $this->client->request('GET', '/settings/tesla');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tesla app');
        self::assertSelectorTextContains('#no-key-pair', 'polaris:tesla:keygen');
        self::assertSelectorNotExists('form');
    }

    public function testThePageShowsTheFormAndNeverTheSecret(): void
    {
        $user = self::signIn($this->client);
        $app = $this->createApp($user);
        $app->configure(
            'client-id',
            self::getContainer()->get(SecretEncrypter::class)->encrypt(self::SECRET),
            \Polaris\Tesla\Domain\AppDomain::fromString('polaris.example.com'),
            [FleetRegion::EuropeMiddleEastAfrica],
        );
        $this->entityManager->flush();

        $this->client->request('GET', '/settings/tesla');

        self::assertResponseIsSuccessful();
        self::assertInputValueSame(self::FORM . '[clientId]', 'client-id');
        self::assertInputValueSame(self::FORM . '[domain]', 'polaris.example.com');
        self::assertInputValueSame(self::FORM . '[clientSecret]', '');
        self::assertSelectorExists('input[type=password][name="' . self::FORM . '[clientSecret]"]:not([required])');
        self::assertSelectorExists('input[name="' . self::FORM . '[clientSecret]"][placeholder="••••••••"]');
        self::assertSelectorExists('input[name="' . self::FORM . '[regions][]"][value=eu][checked]');
        self::assertSelectorNotExists('input[name="' . self::FORM . '[regions][]"][value=na][checked]');
        self::assertSelectorExists('#status');
        self::assertSelectorTextContains('#status', 'https://polaris.example.com/.well-known/appspecific/com.tesla.3p.public-key.pem');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(self::SECRET, $html);
        self::assertStringNotContainsString((string) $app->getEncryptedClientSecret(), $html);
        self::assertStringNotContainsString('v1:', $html);
    }

    public function testTheSettingsExplainHowTokensAreStoredWithLinksToTheSource(): void
    {
        $this->createApp(self::signIn($this->client));

        $crawler = $this->client->request('GET', '/settings/tesla');

        self::assertSelectorTextContains('#token-storage h2', 'How your Tesla tokens are stored');
        self::assertSelectorTextContains('#token-storage', 'libsodium');
        self::assertSelectorTextContains('#token-storage', 'a copy of the database alone is useless');
        self::assertStringNotContainsString('POLARIS_ENCRYPTION_KEY', (string) $this->client->getResponse()->getContent(), 'How the key is kept is for the installation guide, not for the page.');
        foreach (['encrypted', 'key', 'rotation', 'hidden', 'scopes', 'disconnect', 'audit'] as $section) {
            self::assertSelectorExists('#token-storage [data-storage=' . $section . '] svg[aria-hidden=true]', 'Each paragraph has a glyph: ' . $section);
        }
        $links = $crawler->filter('#token-storage a')->each(static fn ($link): string => (string) $link->attr('href'));
        self::assertContains('https://github.com/RSickenberg/polaris', $links);
        self::assertContains('https://github.com/RSickenberg/polaris/blob/main/src/Shared/Service/SecretEncrypter.php', $links);
        self::assertContains('https://github.com/RSickenberg/polaris/blob/main/src/Tesla/Service/LockedTokenProvider.php', $links);
        self::assertContains('https://github.com/RSickenberg/polaris/blob/main/docs/adr/0007-secrets-encrypted-at-rest.md', $links);
        self::assertSelectorExists('#token-storage a[href^="https://github.com"][rel="noopener noreferrer"][target=_blank]');
        self::assertSelectorExists('#status ~ #token-storage', 'The card is the last one of the page.');
        self::assertSelectorNotExists('#token-storage ~ [data-slot=card]');
    }

    public function testWithoutAStoredSecretThereAreNoDots(): void
    {
        $this->createApp(self::signIn($this->client));

        $this->client->request('GET', '/settings/tesla');

        self::assertSelectorExists('input[name="' . self::FORM . '[clientSecret]"][required]');
        self::assertSelectorNotExists('input[name="' . self::FORM . '[clientSecret]"][placeholder]');
    }

    public function testChinaIsListedButCannotBeTicked(): void
    {
        $this->createApp(self::signIn($this->client));

        $this->client->request('GET', '/settings/tesla');

        self::assertSelectorExists('input[name="' . self::FORM . '[regions][]"][value=cn][disabled]');
        self::assertSelectorNotExists('input[name="' . self::FORM . '[regions][]"][value=na][disabled]');
        self::assertSelectorNotExists('input[name="' . self::FORM . '[regions][]"][value=eu][disabled]');
        self::assertSelectorNotExists('input[name="' . self::FORM . '[regions][]"][required]');
        self::assertSelectorTextContains('label[for=' . self::FORM . '_regions_2]', 'China');
    }

    public function testASaveStoresTheEncryptedSecretAndRedirects(): void
    {
        $user = self::signIn($this->client);
        $this->createApp($user);

        $this->submit(['clientSecret' => self::SECRET, 'domain' => ' Polaris.Example.com ', 'regions' => ['eu', 'na']]);

        self::assertResponseRedirects('/settings/tesla');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-slot=alert]', 'The Tesla app settings are saved.');

        $this->entityManager->clear();
        $app = $this->entityManager->getRepository(TeslaApp::class)->findOneBy([]);
        self::assertInstanceOf(TeslaApp::class, $app);
        self::assertSame('client-id', $app->getClientId());
        self::assertSame('polaris.example.com', $app->getDomain()?->toString());
        self::assertSame([FleetRegion::NorthAmericaAsiaPacific, FleetRegion::EuropeMiddleEastAfrica], $app->getEnabledRegions());
        $ciphertext = (string) $app->getEncryptedClientSecret();
        self::assertStringStartsWith('v1:', $ciphertext);
        self::assertSame(self::SECRET, self::getContainer()->get(SecretEncrypter::class)->decrypt($ciphertext));
    }

    public function testTheWakeCapDefaultsToThreeAndIsSavedAndBounded(): void
    {
        $this->createApp(self::signIn($this->client));
        $this->client->request('GET', '/settings/tesla');
        self::assertInputValueSame(self::FORM . '[dailyWakeCap]', '3');

        $this->submit(['clientSecret' => self::SECRET, 'dailyWakeCap' => '5']);
        self::assertResponseRedirects('/settings/tesla');
        $this->entityManager->clear();
        self::assertSame(5, $this->entityManager->getRepository(TeslaApp::class)->findOneBy([])?->getDailyWakeCap());

        $this->submit(['dailyWakeCap' => '0']);
        self::assertResponseRedirects('/settings/tesla');
        $this->entityManager->clear();
        self::assertSame(0, $this->entityManager->getRepository(TeslaApp::class)->findOneBy([])?->getDailyWakeCap(), '0 turns waking off.');

        $this->submit(['dailyWakeCap' => '21']);
        self::assertResponseStatusCodeSame(422);
        $this->entityManager->clear();
        self::assertSame(0, $this->entityManager->getRepository(TeslaApp::class)->findOneBy([])?->getDailyWakeCap());
    }

    public function testKeepingTheVehicleDataIsOnByDefaultAndTheUserSwitchesItOffAndOn(): void
    {
        $this->createApp(self::signIn($this->client));
        $this->client->request('GET', '/settings/tesla');
        self::assertSelectorExists('input[type=checkbox][name="' . self::FORM . '[keepVehicleData]"][checked]');
        self::assertSelectorTextContains('label[for=' . self::FORM . '_keepVehicleData]', 'Keep what the car reports');
        self::assertSelectorTextContains('[id$=keepVehicleData_help]', 'never the location');
        self::assertSelectorExists('[id$=keepVehicleData_help] br', 'The last sentence of the help is on its own line.');

        $this->submit(['clientSecret' => self::SECRET, 'keepVehicleData' => false]);
        self::assertResponseRedirects('/settings/tesla');
        $this->entityManager->clear();
        self::assertFalse($this->entityManager->getRepository(TeslaApp::class)->findOneBy([])?->keepsVehicleData());

        $this->client->request('GET', '/settings/tesla');
        self::assertSelectorExists('input[type=checkbox][name="' . self::FORM . '[keepVehicleData]"]:not([checked])');

        $this->submit(['keepVehicleData' => '1']);
        self::assertResponseRedirects('/settings/tesla');
        $this->entityManager->clear();
        self::assertTrue($this->entityManager->getRepository(TeslaApp::class)->findOneBy([])?->keepsVehicleData());
    }

    public function testAnEmptySecretKeepsTheStoredOne(): void
    {
        $user = self::signIn($this->client);
        $this->createApp($user);
        $this->submit(['clientSecret' => self::SECRET]);
        $this->entityManager->clear();
        $before = $this->entityManager->getRepository(TeslaApp::class)->findOneBy([])?->getEncryptedClientSecret();

        $this->submit(['clientId' => 'other-id', 'clientSecret' => '']);

        self::assertResponseRedirects('/settings/tesla');
        $this->entityManager->clear();
        $app = $this->entityManager->getRepository(TeslaApp::class)->findOneBy([]);
        self::assertInstanceOf(TeslaApp::class, $app);
        self::assertSame('other-id', $app->getClientId());
        self::assertSame($before, $app->getEncryptedClientSecret());
    }

    public function testTheFirstSaveNeedsASecret(): void
    {
        $this->createApp(self::signIn($this->client));

        $this->submit(['clientSecret' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-slot=field-error]', 'Enter the client secret.');
        $this->entityManager->clear();
        self::assertFalse($this->entityManager->getRepository(TeslaApp::class)->findOneBy([])?->isConfigured());
    }

    public function testAnInvalidDomainAndNoRegionAreRefused(): void
    {
        $this->createApp(self::signIn($this->client));

        $this->submit(['clientSecret' => self::SECRET, 'domain' => 'https://polaris.example.com/key', 'regions' => []]);

        self::assertResponseStatusCodeSame(422);
        $errors = $this->client->getCrawler()->filter('[data-slot=field-error]')->each(static fn ($node): string => trim($node->text()));
        self::assertContains('Enter the domain only, for example polaris.example.com, without https:// or a path.', $errors);
        self::assertContains('Enable at least one region.', $errors);
    }

    public function testChinaIsRefusedEvenWhenThePostIsForged(): void
    {
        $this->createApp(self::signIn($this->client));

        $this->submit(['clientSecret' => self::SECRET, 'regions' => ['eu', 'cn']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-slot=field-error]', 'This region is not supported.');
        $this->entityManager->clear();
        self::assertSame([], $this->entityManager->getRepository(TeslaApp::class)->findOneBy([])?->getEnabledRegions());
    }

    public function testAnotherUserNeverSeesTheApp(): void
    {
        $owner = self::createUser();
        $app = $this->createApp($owner);
        $app->configure(
            'secret-client-id',
            'v1:ciphertext',
            \Polaris\Tesla\Domain\AppDomain::fromString('polaris.example.com'),
            [FleetRegion::EuropeMiddleEastAfrica],
        );
        $this->entityManager->flush();

        self::signIn($this->client, self::createUser('other@example.test'));
        $this->client->request('GET', '/settings/tesla');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#no-key-pair');
        self::assertStringNotContainsString('secret-client-id', (string) $this->client->getResponse()->getContent());
    }

    public function testThePageIsTranslatedToFrench(): void
    {
        $this->createApp(self::signIn($this->client));
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'fr');

        $this->client->request('GET', '/settings/tesla');

        self::assertSelectorTextContains('h1', 'Application Tesla');
        self::assertSelectorTextContains('label[for=' . self::FORM . '_clientSecret]', 'Secret client');
        self::assertSelectorTextContains('label[for=' . self::FORM . '_regions_2]', 'Chine');
    }

    public function testTheAccountMenuLinksToThePage(): void
    {
        self::signIn($this->client);

        $this->client->request('GET', '/settings');

        self::assertSelectorExists('a[href="/settings/tesla"]');
    }

    /**
     * Posts the settings form with valid defaults, overridden by $values.
     *
     * @param array<string, mixed> $values
     */
    private function submit(array $values): void
    {
        $crawler = $this->client->request('GET', '/settings/tesla');
        $form = $crawler->selectButton('Save the Tesla app')->form();
        $posted = $form->getPhpValues();
        $posted[self::FORM] = array_merge(
            (array) $posted[self::FORM],
            ['clientId' => 'client-id', 'domain' => 'polaris.example.com', 'regions' => ['eu'], 'clientSecret' => ''],
            $values,
        );
        // An unticked box is not posted at all: a value of false removes the field.
        $posted[self::FORM] = array_filter((array) $posted[self::FORM], static fn (mixed $value): bool => false !== $value);
        $this->client->request('POST', '/settings/tesla', $posted);
    }

    private function createApp(User $owner): TeslaApp
    {
        $app = new TeslaApp($owner, 'public key', 'v1:private', CarbonImmutable::parse('2026-10-20 12:00:00 UTC'));
        $this->entityManager->persist($app);
        $this->entityManager->flush();

        return $app;
    }
}
