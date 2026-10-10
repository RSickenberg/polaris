<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Shared;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Shared\Domain\Distance;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The global form theme renders every row as a kit Field around a kit Input, NativeSelect or Button,
 * and keeps the accessibility wiring (label, help and errors) intact in both locales.
 */
#[CoversNothing]
final class FormThemeTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string FRENCH = 'fr-CH, fr;q=0.9, en;q=0.8';

    private KernelBrowser $client;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        self::mockTime('2026-10-20 12:00:00 UTC');
        $this->client = self::createClient();
        $this->vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000060'), 'Model 3');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($this->vehicle);
        $entityManager->persist(OdometerReading::manual($this->vehicle, new \Carbon\CarbonImmutable('2026-10-01 08:00', 'UTC'), Distance::fromKilometres(10_000)));
        $entityManager->flush();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pages(): iterable
    {
        yield 'readings in English' => ['readings', 'en'];
        yield 'readings in French' => ['readings', 'fr'];
        yield 'lease in English' => ['lease', 'en'];
        yield 'lease in French' => ['lease', 'fr'];
    }

    #[DataProvider('pages')]
    public function testEveryLabelIsTiedToItsControl(string $page, string $locale): void
    {
        $crawler = $this->get($page, $locale);

        $labels = $crawler->filter('form label[for]');
        self::assertGreaterThan(2, $labels->count());
        foreach ($labels->each(static fn (Crawler $label): string => (string) $label->attr('for')) as $for) {
            self::assertNotSame('', $for);
            $control = $crawler->filter(\sprintf('form [id="%s"]', $for));
            self::assertCount(1, $control);
            self::assertContains($control->nodeName(), ['input', 'select']);
        }
    }

    #[DataProvider('pages')]
    public function testRequiredFieldsKeepTheRequiredAttribute(string $page, string $locale): void
    {
        $crawler = $this->get($page, $locale);

        // A select without placeholder is never marked required: Symfony's default theme drops it too, as an option is always chosen.
        $controls = $crawler->filter('form input:not([type=hidden])');
        self::assertGreaterThan(1, $controls->count());
        self::assertCount($controls->count(), $crawler->filter('form input[required]:not([type=hidden])'));
    }

    #[DataProvider('pages')]
    public function testRequiredLabelsShowARedAsterisk(string $page, string $locale): void
    {
        $crawler = $this->get($page, $locale);

        $labels = $crawler->filter('form label[for]');
        self::assertCount($labels->count(), $crawler->filter('form label[for] span.text-destructive[aria-hidden=true]'));
        self::assertSame('*', $crawler->filter('form label[for] span.text-destructive')->first()->text());
    }

    #[DataProvider('pages')]
    public function testFormKeepsItsTokenAndTranslatedSubmitButton(string $page, string $locale): void
    {
        $crawler = $this->get($page, $locale);

        self::assertCount(1, $crawler->filter('form input[type=hidden][name$="[_token]"]'));
        $button = $crawler->filter('form button[type=submit][data-slot=button]');
        self::assertCount(1, $button);
        self::assertSame('fr' === $locale ? ('readings' === $page ? 'Ajouter le relevé' : 'Enregistrer les conditions') : ('readings' === $page ? 'Add reading' : 'Save lease terms'), trim($button->text()));
    }

    #[DataProvider('locales')]
    public function testInvalidSubmitShowsTheErrorInsideTheFieldAndWiresTheControl(string $locale, string $message): void
    {
        $this->get('readings', $locale);
        $this->client->submitForm('fr' === $locale ? 'Ajouter le relevé' : 'Add reading', [
            'odometer_reading[readAt]' => '2026-10-02T08:00',
            'odometer_reading[odometer]' => '-1',
            'odometer_reading[distanceUnit]' => 'km',
        ]);

        self::assertResponseStatusCodeSame(422);
        $crawler = $this->client->getCrawler();
        $field = $crawler->filter('form [data-slot=field][data-invalid=true]');
        self::assertCount(1, $field);
        self::assertStringContainsString($message, $field->filter('[data-slot=field-error]')->text());

        $control = $field->filter('input[name="odometer_reading[odometer]"]');
        self::assertSame('true', $control->attr('aria-invalid'));
        $describedBy = explode(' ', (string) $control->attr('aria-describedby'));
        self::assertNotSame([''], $describedBy);
        foreach ($describedBy as $id) {
            self::assertCount(1, $crawler->filter(\sprintf('[id="%s"]', $id)), $id);
        }
        self::assertSame($field->filter('[data-slot=field-error]')->attr('id'), $describedBy[array_key_last($describedBy)]);
        self::assertCount(0, $crawler->filter('form [data-slot=field]:not([data-invalid]) [aria-invalid=true]'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function locales(): iterable
    {
        yield 'English' => ['en', 'This value should be'];
        yield 'French' => ['fr', 'Cette valeur doit être'];
    }

    public function testHelpTextsAreTranslatedAndDescribeTheirControl(): void
    {
        $english = $this->get('lease', 'en');
        self::assertSame('Counted per year or for the whole term, as chosen above.', trim($english->filter('#lease_contract_allowance_help')->text()));
        self::assertStringContainsString('lease_contract_allowance_help', (string) $english->filter('#lease_contract_allowance')->attr('aria-describedby'));
        self::assertSame('Price charged for each kilometre over the allowance.', trim($english->filter('#lease_contract_excessCostPerKm_help')->text()));

        $french = $this->get('lease', 'fr');
        self::assertSame('Comptée par an ou sur toute la durée, selon le choix ci-dessus.', trim($french->filter('#lease_contract_allowance_help')->text()));
        self::assertSame('Prix facturé pour chaque kilomètre au-delà du forfait.', trim($french->filter('#lease_contract_excessCostPerKm_help')->text()));
        self::assertCount(5, $french->filter('form [data-slot=field-description]'));
    }

    public function testSelectKeepsItsOptionsAndSelectedChoice(): void
    {
        $crawler = $this->get('lease', 'en');

        $select = $crawler->filter('select[name="lease_contract[allowanceBasis]"]');
        self::assertCount(1, $select);
        self::assertSame(['per_year', 'total'], $select->filter('option')->each(static fn (Crawler $option): string => $option->attr('value') ?? ''));
        self::assertCount(1, $select->filter('option[selected]'));
        self::assertCount(1, $crawler->filter('[data-slot=native-select-wrapper] [data-slot=native-select-icon]')->reduce(static fn (Crawler $icon, int $i): bool => 0 === $i));
        self::assertGreaterThan(0, $crawler->filter('[data-slot=native-select-wrapper] svg')->count());
    }

    public function testReadingsTableUsesTheKitTable(): void
    {
        $crawler = $this->get('readings', 'en');

        self::assertCount(1, $crawler->filter('[data-slot=card] [data-slot=table-container] table'));
        self::assertCount(3, $crawler->filter('thead [data-slot=table-head]'));
        self::assertCount(3, $crawler->filter('tbody tr[data-slot=table-row] [data-slot=table-cell]'));
        self::assertStringContainsString('10,000', $crawler->filter('tbody td:nth-child(2)')->text());
    }

    private function get(string $page, string $locale): Crawler
    {
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'fr' === $locale ? self::FRENCH : 'en');
        $this->client->request('GET', \sprintf('/vehicles/%s/%s', $this->vehicle->getId()->toBase32(), $page));
        self::assertResponseIsSuccessful();

        return $this->client->getCrawler();
    }
}
