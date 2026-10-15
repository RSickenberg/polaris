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
use Symfony\Component\BrowserKit\Cookie;

/**
 * The language switcher stores the chosen locale in a cookie, which wins over the browser's
 * Accept-Language, and only ever redirects to a path of this site.
 */
#[CoversNothing]
final class LocaleSwitchTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;
    private string $path;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::signIn($this->client);
        $vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000052'), 'Model 3', self::createUser());
        self::getContainer()->get(EntityManagerInterface::class)->persist($vehicle);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->path = \sprintf('/vehicles/%s/readings', $vehicle->getId()->toBase32());
    }

    public function testSwitchingStoresTheLocaleAndGoesBack(): void
    {
        $this->client->request('GET', '/locale/fr', ['redirect' => $this->path]);

        self::assertResponseRedirects($this->path);
        self::assertResponseCookieValueSame('polaris_locale', 'fr');

        $this->client->followRedirect();
        self::assertSelectorExists('html[lang="fr"]');
        self::assertSelectorTextContains('h1', 'Historique du compteur');
    }

    public function testTheCookieWinsOverAcceptLanguage(): void
    {
        $this->client->getCookieJar()->set(new Cookie('polaris_locale', 'fr'));
        $this->client->request('GET', $this->path, server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        self::assertSelectorExists('html[lang="fr"]');
    }

    public function testAnUnsupportedCookieIsIgnored(): void
    {
        $this->client->getCookieJar()->set(new Cookie('polaris_locale', 'de'));
        $this->client->request('GET', $this->path, server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);

        self::assertSelectorExists('html[lang="fr"]');
    }

    public function testAnUnsupportedLocaleIsNotFound(): void
    {
        $this->client->request('GET', '/locale/de', ['redirect' => $this->path]);

        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('foreignTargets')]
    public function testOnlyLocalPathsAreRedirectTargets(string $target): void
    {
        $this->client->request('GET', '/locale/fr', ['redirect' => $target]);

        self::assertResponseRedirects('/');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignTargets(): iterable
    {
        yield 'absolute URL' => ['https://evil.example/'];
        yield 'protocol-relative URL' => ['//evil.example/'];
        yield 'backslash' => ['/\\evil.example/'];
        yield 'no leading slash' => ['evil.example'];
        yield 'line break' => ["/ok\r\nSet-Cookie: x=y"];
        yield 'missing' => [''];
    }
}
