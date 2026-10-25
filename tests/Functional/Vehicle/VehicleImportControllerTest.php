<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Vehicle;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Account\Entity\User;
use Polaris\Tests\Support\FleetApiResponses;
use Polaris\Tests\Support\SignsIn;
use Polaris\Tests\Support\TeslaFixtures;
use Polaris\Vehicle\Controller\VehicleImportController;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(VehicleImportController::class)]
final class VehicleImportControllerTest extends WebTestCase
{
    use SignsIn;
    use TeslaFixtures;

    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The test replaces the HTTP client in the container: keep the same container for the whole test.
        $this->client->disableReboot();
        $this->user = self::signIn($this->client);
    }

    public function testAnonymousVisitorsAreSentToTheLogin(): void
    {
        self::ensureKernelShutdown();
        $anonymous = self::createClient();

        $anonymous->request('GET', '/vehicles/import');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testWithoutAConnectionItSaysSoAndLinksToTheTeslaSettings(): void
    {
        $this->client->request('GET', '/vehicles/import');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-import-status="not_connected"]');
        self::assertSelectorExists('a[href="/settings/tesla"]');
    }

    public function testTheNewVehiclesOfTheAccountAreListedAndTheOnesAlreadyAddedAreNot(): void
    {
        $this->connect();
        $this->add(FleetApiResponses::VIN, 'Mine');

        $this->client->request('GET', '/vehicles/import');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Raven (' . FleetApiResponses::OTHER_VIN . ')');
        self::assertSelectorTextNotContains('main', 'Lucia');
    }

    public function testTickedVehiclesAreImportedForTheUser(): void
    {
        $this->connect(2);
        $crawler = $this->client->request('GET', '/vehicles/import');

        $form = $crawler->selectButton('Import selected vehicles')->form();
        $box = $form->get('vehicle_import[vins][1]');
        self::assertInstanceOf(ChoiceFormField::class, $box);
        $box->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/');
        $vehicles = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Vehicle::class)->findAll();
        self::assertCount(1, $vehicles);
        self::assertSame(FleetApiResponses::OTHER_VIN, $vehicles[0]->getVin()->value());
        self::assertSame($this->user->getId()->toRfc4122(), $vehicles[0]->getOwner()->getId()->toRfc4122());

        $this->client->followRedirect();
        self::assertSelectorExists('[data-flash="success"].text-green-900');
        self::assertSelectorTextContains('main', '1 vehicle imported');
        self::assertSelectorTextContains('main', 'Raven');
    }

    public function testNothingTickedIsRefused(): void
    {
        $this->connect(2);
        $crawler = $this->client->request('GET', '/vehicles/import');

        $this->client->submit($crawler->selectButton('Import selected vehicles')->form());

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Tick at least one vehicle.');
    }

    public function testAVinTeslaDidNotListIsRefused(): void
    {
        $this->connect(2);
        $crawler = $this->client->request('GET', '/vehicles/import');
        $token = $crawler->filter('input[name="vehicle_import[_token]"]')->attr('value');

        $this->client->request('POST', '/vehicles/import', ['vehicle_import' => ['vins' => ['5YJ3E7EB2NF000999'], '_token' => $token, 'save' => '']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], self::getContainer()->get(EntityManagerInterface::class)->getRepository(Vehicle::class)->findAll());
    }

    public function testWhenEverythingIsAlreadyThereItSaysSo(): void
    {
        $this->connect();
        $this->add(FleetApiResponses::VIN, 'A');
        $this->add(FleetApiResponses::OTHER_VIN, 'B');

        $this->client->request('GET', '/vehicles/import');

        self::assertSelectorTextContains('main', 'already in Polaris');
    }

    public function testATeslaErrorShowsAMessageWithoutDetails(): void
    {
        $this->connect(1, 500);

        $this->client->request('GET', '/vehicles/import');

        self::assertSelectorExists('[data-import-status="error"]');
    }

    public function testTheHomePageLinksToTheImport(): void
    {
        $this->client->request('GET', '/');
        self::assertSelectorExists('main a[href="/vehicles/import"]');

        $this->add(FleetApiResponses::VIN, 'Mine');
        $this->client->request('GET', '/');
        self::assertSelectorExists('main a[href="/vehicles/import"]');
    }

    private function connect(int $responses = 1, int $status = 200): void
    {
        $app = self::createConfiguredApp($this->user);
        self::createToken($app, CarbonImmutable::now()->addHours(2));
        self::getContainer()->set('http_client', new MockHttpClient(array_fill(0, $responses * 2, new MockResponse(FleetApiResponses::vehicleList(), ['http_code' => $status]))));
    }

    private function add(string $vin, string $name): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new Vehicle(new Vin($vin), $name, $this->user));
        $entityManager->flush();
    }
}
