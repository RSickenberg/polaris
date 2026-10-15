<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Dashboard;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Dashboard\Controller\HomeController;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversClass(HomeController::class)]
final class HomeControllerTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::signIn($this->client);
    }

    public function testWithoutAVehicleItShowsAnEmptyState(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Your vehicles');
        self::assertSelectorTextContains('main', 'There is no vehicle yet.');
    }

    public function testItListsTheVehiclesByNameWithTheirLinks(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $second = new Vehicle(new Vin('5YJ3E7EB2NF000081'), 'Model Y', self::createUser());
        $first = new Vehicle(new Vin('5YJ3E7EB2NF000082'), 'Cybertruck', self::createUser());
        $entityManager->persist($second);
        $entityManager->persist($first);
        $entityManager->flush();

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Your vehicles');
        $names = $this->client->getCrawler()->filter('main h2')->each(static fn ($heading): string => $heading->text());
        self::assertSame(['Cybertruck', 'Model Y'], $names);
        self::assertSelectorExists(\sprintf('main a[href="/vehicles/%s"]', $first->getId()->toBase32()));
        self::assertSelectorExists(\sprintf('main a[href="/vehicles/%s/readings"]', $first->getId()->toBase32()));
        self::assertSelectorExists(\sprintf('main a[href="/vehicles/%s/lease"]', $first->getId()->toBase32()));
    }
}
