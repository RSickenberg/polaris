<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Shared;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The pages render inside the app shell: sidebar with the vehicle navigation, theme toggle
 * and language switcher, all translated. The behaviour of the toggle and of the mobile
 * sidebar is JavaScript and is checked by hand.
 */
#[CoversNothing]
final class LayoutTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;
    private string $id;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::signIn($this->client);
        $vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000051'), 'Model 3', self::createUser());
        self::getContainer()->get(EntityManagerInterface::class)->persist($vehicle);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->id = $vehicle->getId()->toBase32();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pages(): iterable
    {
        yield 'dashboard' => ['dashboard_show', '/vehicles/%s'];
        yield 'readings' => ['reading_history', '/vehicles/%s/readings'];
        yield 'lease terms' => ['lease_settings', '/vehicles/%s/lease'];
    }

    #[DataProvider('pages')]
    public function testPageRendersInsideTheShell(string $route, string $path): void
    {
        $this->client->request('GET', \sprintf($path, $this->id));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('aside[aria-label="Sidebar"]');
        self::assertSelectorExists('main#main-content h1');
        self::assertSelectorExists('a[href="#main-content"]');
        self::assertSelectorExists('button[aria-label="Toggle the sidebar"]');
        self::assertSelectorExists('script:contains("polaris:theme")');
        self::assertSelectorExists('link[rel="stylesheet"][href^="/assets/styles/app"]');
    }

    public function testNavigationLinksTheVehiclePagesAndMarksTheCurrentOne(): void
    {
        $crawler = $this->client->request('GET', \sprintf('/vehicles/%s/lease', $this->id));

        $links = $crawler->filter('nav[aria-label="Main navigation"] a');
        self::assertCount(3, $links);
        self::assertSame(\sprintf('/vehicles/%s', $this->id), $links->eq(0)->attr('href'));
        self::assertSame('Dashboard', trim($links->eq(0)->text()));
        self::assertSame('false', $links->eq(0)->attr('data-active'));
        self::assertSame(\sprintf('/vehicles/%s/readings', $this->id), $links->eq(1)->attr('href'));
        self::assertSame('Odometer readings', trim($links->eq(1)->text()));
        self::assertSame('false', $links->eq(1)->attr('data-active'));
        self::assertSame(\sprintf('/vehicles/%s/lease', $this->id), $links->eq(2)->attr('href'));
        self::assertSame('Lease terms', trim($links->eq(2)->text()));
        self::assertSame('page', $links->eq(2)->attr('aria-current'));
    }

    public function testNavigationMarksTheDashboardWhenOnIt(): void
    {
        $crawler = $this->client->request('GET', \sprintf('/vehicles/%s', $this->id));

        self::assertSame('page', $crawler->filter('nav[aria-label="Main navigation"] a')->eq(0)->attr('aria-current'));
    }

    public function testShellIsTranslatedToFrench(): void
    {
        $this->client->request('GET', \sprintf('/vehicles/%s/readings', $this->id), server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);

        self::assertSelectorExists('html[lang="fr"]');
        self::assertSelectorExists('aside[aria-label="Barre latérale"]');
        self::assertSelectorExists('nav[aria-label="Navigation principale"]');
        self::assertSelectorExists('nav a:contains("Tableau de bord")');
        self::assertSelectorExists('nav a:contains("Relevés du compteur")');
        self::assertSelectorExists('nav a:contains("Conditions du leasing")');
        self::assertSelectorExists('button[aria-label="Afficher ou masquer la barre latérale"]');
        self::assertSelectorTextContains('dialog', 'Affiche la barre latérale sur mobile.');
    }

    /**
     * @param list<string> $options
     */
    #[DataProvider('themeLabels')]
    public function testThemeToggleRendersInEachLocale(string $acceptLanguage, string $label, array $options): void
    {
        $crawler = $this->client->request('GET', \sprintf('/vehicles/%s/readings', $this->id), server: ['HTTP_ACCEPT_LANGUAGE' => $acceptLanguage]);

        self::assertSelectorExists(\sprintf('[data-controller~="theme"] button[aria-label="%s"]', $label));
        $radios = $crawler->filter('[data-controller~="theme"] [role="menuitemradio"]');
        self::assertSame(['light', 'dark', 'system'], $radios->each(static fn ($radio): ?string => $radio->attr('data-value')));
        self::assertSame($options, $radios->each(static fn ($radio): string => trim($radio->text())));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function themeLabels(): iterable
    {
        yield 'en' => ['en', 'Theme', ['Light', 'Dark', 'System']];
        yield 'fr' => ['fr', 'Thème', ['Clair', 'Sombre', 'Système']];
    }

    public function testLanguageSwitcherLinksBackToTheCurrentPage(): void
    {
        $crawler = $this->client->request('GET', \sprintf('/vehicles/%s/readings', $this->id));

        $links = $crawler->filter('[role="menu"] a[lang]');
        self::assertSame(['en', 'fr'], $links->each(static fn ($link): ?string => $link->attr('lang')));
        self::assertSame(
            \sprintf('/locale/fr?redirect=/vehicles/%s/readings', $this->id),
            $links->eq(1)->attr('href'),
        );
        self::assertSame('true', $links->eq(0)->attr('aria-current'));
        self::assertSame('Français', trim($links->eq(1)->text()));
    }
}
