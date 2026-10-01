<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Shared;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Shared\Domain\Distance;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The locale of a page follows the Accept-Language header, falls back to English, and drives
 * the translations, the number format and the date format; dates stay stored in UTC.
 */
#[CoversNothing]
final class LocaleTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $client;
    private string $url;

    protected function setUp(): void
    {
        self::mockTime('2026-10-20 12:00:00 UTC');
        $this->client = self::createClient();
        $vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000050'), 'Model 3');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($vehicle);
        $entityManager->persist(OdometerReading::manual($vehicle, new \Carbon\CarbonImmutable('2026-10-01 08:00', 'UTC'), Distance::fromKilometres(12_345)));
        $entityManager->flush();
        $this->url = \sprintf('/vehicles/%s/readings', $vehicle->getId()->toBase32());
    }

    public function testPageIsInFrenchWhenTheBrowserAsksForFrench(): void
    {
        $this->client->request('GET', $this->url, server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-CH, fr;q=0.9, en;q=0.8']);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Language', 'fr');
        self::assertSelectorExists('html[lang="fr"]');
        self::assertSelectorTextContains('h1', 'Historique du compteur, Model 3');
        self::assertSelectorTextContains('h2', 'Ajouter un relevé');
        self::assertSelectorExists('button:contains("Ajouter le relevé")');
        $cells = $this->client->getCrawler()->filter('tbody td')->each(static fn ($cell): string => self::text($cell->text()));
        self::assertSame(['1 oct. 2026, 08:00 UTC', '12 345', 'Manuelle'], $cells);
    }

    public function testPageIsInEnglishByDefault(): void
    {
        $this->client->request('GET', $this->url);

        self::assertResponseHeaderSame('Content-Language', 'en');
        self::assertSelectorExists('html[lang="en"]');
        self::assertSelectorTextContains('h1', 'Odometer history, Model 3');
        $cells = $this->client->getCrawler()->filter('tbody td')->each(static fn ($cell): string => self::text($cell->text()));
        self::assertSame(['Oct 1, 2026, 8:00 AM UTC', '12,345', 'Manual'], $cells);
    }

    public function testUnsupportedLanguageFallsBackToEnglish(): void
    {
        $this->client->request('GET', $this->url, server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']);

        self::assertSelectorExists('html[lang="en"]');
        self::assertSelectorTextContains('h1', 'Odometer history');
    }

    public function testValidationAndRejectionMessagesAreInFrench(): void
    {
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'fr');
        $this->client->request('GET', $this->url);
        $this->client->submitForm('Ajouter le relevé', ['odometer_reading[readAt]' => '2026-10-02T08:00', 'odometer_reading[odometer]' => '9000', 'odometer_reading[distanceUnit]' => 'km']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Le compteur ne peut pas être inférieur au relevé précédent (12 345 km le 1 oct. 2026 à 08:00 UTC).', self::text($this->client->getCrawler()->filter('body')->text()));
    }

    private static function text(string $text): string
    {
        return preg_replace('/\s+/u', ' ', str_replace(["\u{202f}", "\u{a0}"], ' ', $text)) ?? '';
    }
}
