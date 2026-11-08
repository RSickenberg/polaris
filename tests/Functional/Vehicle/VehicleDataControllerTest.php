<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Vehicle;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Lease\Form\Model\LeaseContractData;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Reading\Service\OdometerReadingService;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Controller\VehicleDataController;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Polaris\Vehicle\Form\VehicleDataImportType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[CoversClass(VehicleDataController::class)]
#[CoversClass(VehicleDataImportType::class)]
final class VehicleDataControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use SignsIn;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Vehicle $source;
    private Vehicle $target;

    protected function setUp(): void
    {
        self::mockTime('2026-07-03 00:05:00 UTC');
        $this->client = self::createClient();
        self::signIn($this->client);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $owner = self::createUser();
        $this->source = new Vehicle(new Vin('5YJ3E7EB2NF000601'), 'Model 3', $owner);
        $this->target = new Vehicle(new Vin('5YJ3E7EB2NF000602'), 'Model Y', $owner);
        $this->entityManager->persist($this->source);
        $this->entityManager->persist($this->target);
        $this->entityManager->flush();

        $data = new LeaseContractData();
        $data->startDate = new \DateTimeImmutable('2026-01-01');
        $data->endDate = new \DateTimeImmutable('2027-01-01');
        $data->allowance = 15_000;
        $data->startOdometer = 0;
        $data->excessCostPerKm = '0.12';
        $data->currency = Currency::CHF;
        $data->tolerancePercent = 0;
        self::getContainer()->get(LeaseTermsService::class)->save($this->source, $data);
        $this->entityManager->persist(OdometerReading::manual($this->source, CarbonImmutable::parse('2026-06-01 10:00:00', 'UTC'), Distance::fromKilometres(10_000)));
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob(sys_get_temp_dir() . '/polaris-test-*.json') ?: []);
        parent::tearDown();
    }

    public function testThePageOffersTheExportAndTheImport(): void
    {
        $this->client->request('GET', $this->url($this->source));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Data | Model 3');
        self::assertSelectorExists(\sprintf('[data-data=export] a[href="%s"]', $this->url($this->source) . '/export'));
        self::assertSelectorExists('[data-data=import] input[type=file]');
        self::assertSelectorExists(\sprintf('nav a[href="%s"]', $this->url($this->source)));
    }

    public function testTheExportIsADownloadOfJsonWithoutTheVin(): void
    {
        $this->client->request('GET', $this->url($this->source) . '/export');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertStringContainsString('attachment; filename=polaris-model-3-2026-07-03.json', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('5YJ3E7EB2NF000601', $content);
        self::assertStringContainsString('"format": "polaris.vehicle-data"', $content);
    }

    public function testAnExportUploadedToAnotherVehicleAddsItsDataAndSaysWhat(): void
    {
        $file = $this->exportFile();

        $this->upload($this->target, $file);

        self::assertResponseRedirects($this->url($this->target));
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-flash=success]', 'Import done. Lease: created from the file.');
        self::assertSelectorTextContains('[data-flash=success]', 'Readings: 1 added, 0 already there, 0 refused.');
        $this->entityManager->clear();
        $target = $this->entityManager->find(Vehicle::class, $this->target->getId());
        self::assertInstanceOf(Vehicle::class, $target);
        self::assertCount(1, self::getContainer()->get(OdometerReadingService::class)->history($target));
    }

    public function testAFileThatIsNotAnExportIsRefusedWithAMessage(): void
    {
        $this->upload($this->target, $this->temporaryFile('{"hello": "world"}'));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-data=import]', 'The file is not an export of Polaris.');
    }

    public function testAFileWithAnInvalidValueNamesIt(): void
    {
        $this->upload($this->target, $this->temporaryFile('{"format": "polaris.vehicle-data", "version": 1, "lease": null, "readings": [{"readAt": "later", "odometerMetres": 1, "source": "manual"}], "snapshots": []}'));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-data=import]', 'The file has an invalid value: readAt is not a date and time');
    }

    public function testSubmittingWithoutAFileAsksForOne(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->target));

        $this->client->submit($crawler->selectButton('Import')->form());

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-data=import]', 'Choose a file to import.');
    }

    private function exportFile(): string
    {
        $this->client->request('GET', $this->url($this->source) . '/export');

        return $this->temporaryFile((string) $this->client->getResponse()->getContent());
    }

    private function temporaryFile(string $content): string
    {
        $path = sys_get_temp_dir() . '/polaris-test-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, $content);

        return $path;
    }

    private function upload(Vehicle $vehicle, string $file): void
    {
        $crawler = $this->client->request('GET', $this->url($vehicle));
        $form = $crawler->selectButton('Import')->form();
        $this->client->request($form->getMethod(), $form->getUri(), $form->getPhpValues(), ['vehicle_data_import' => ['file' => new UploadedFile($file, 'export.json', 'application/json', null, true)]]);
    }

    private function url(Vehicle $vehicle): string
    {
        return \sprintf('/vehicles/%s/data', $vehicle->getId()->toBase32());
    }
}
