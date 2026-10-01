<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Reading;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Reading\Controller\OdometerReadingController;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[CoversClass(OdometerReadingController::class)]
final class OdometerReadingControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $client;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        self::mockTime('2026-10-20 12:00:00 UTC');
        $this->client = self::createClient();
        $this->vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000030'), 'Model 3');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($this->vehicle);
        $entityManager->flush();
    }

    public function testEmptyHistoryShowsTheForm(): void
    {
        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Model 3');
        self::assertSelectorTextContains('body', 'No reading yet.');
        self::assertSelectorExists('form input[name="odometer_reading[odometer]"]');
        self::assertInputValueSame('odometer_reading[readAt]', '2026-10-20T12:00');
    }

    public function testUnknownVehicleIsNotFound(): void
    {
        $this->client->request('GET', '/vehicles/01JZZZZZZZZZZZZZZZZZZZZZZZ/readings');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSubmittingReadingsListsThemNewestFirstInUtc(): void
    {
        $this->submit('2026-10-01T08:00', '10000', 'km');
        self::assertResponseRedirects($this->url());
        $this->submit('2026-10-02T09:30', '10050', 'km');
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role=status]', 'The reading was added.');
        $rows = $this->client->getCrawler()->filter('tbody tr')->each(static fn ($row): string => preg_replace('/\s+/u', ' ', str_replace("\u{202f}", ' ', trim($row->text()))) ?? '');
        self::assertCount(2, $rows);
        self::assertStringContainsString('Oct 2, 2026, 9:30 AM UTC', $rows[0]);
        self::assertStringContainsString('10,050', $rows[0]);
        self::assertStringContainsString('Oct 1, 2026, 8:00 AM UTC', $rows[1]);
        self::assertStringContainsString('Manual', $rows[1]);
    }

    public function testMilesAreConvertedForTheHistory(): void
    {
        $this->submit('2026-10-01T08:00', '1000', 'mi');
        $this->client->followRedirect();

        self::assertSelectorTextContains('tbody tr', '1,609.3');
    }

    public function testLowerValueShowsATranslatedErrorAndStoresNothing(): void
    {
        $this->submit('2026-10-01T08:00', '10000', 'km');
        $this->submit('2026-10-02T08:00', '9000', 'km');

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The odometer cannot be lower than the previous reading (10,000 km on Oct 1, 2026 at 8:00 AM UTC).', preg_replace('/\s+/u', ' ', str_replace("\u{202f}", ' ', $this->client->getCrawler()->filter('body')->text())) ?? '');
        self::assertCount(1, $this->client->getCrawler()->filter('tbody tr'));
    }

    public function testDuplicateInstantShowsAnError(): void
    {
        $this->submit('2026-10-01T08:00', '10000', 'km');
        $this->submit('2026-10-01T08:00', '10100', 'km');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'A reading already exists at this date and time.');
    }

    public function testInvalidValueIsRefusedByTheValidator(): void
    {
        $this->submit('2026-10-01T08:00', '-1', 'km');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('form .form-error-message, form ul li');
        self::assertSelectorTextContains('body', 'No reading yet.');
    }

    private function submit(string $readAt, string $odometer, string $unit): void
    {
        $this->client->request('GET', $this->url());
        $this->client->submitForm('Add reading', [
            'odometer_reading[readAt]' => $readAt,
            'odometer_reading[odometer]' => $odometer,
            'odometer_reading[distanceUnit]' => $unit,
        ]);
    }

    private function url(): string
    {
        return \sprintf('/vehicles/%s/readings', $this->vehicle->getId()->toBase32());
    }
}
