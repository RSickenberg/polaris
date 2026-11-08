<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Dashboard;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Dashboard\Controller\HomeController;
use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\Tolerance;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Lease\Service\LeaseReturnService;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\Money;
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
        self::assertSelectorTextContains('main', 'No vehicle yet.');
    }

    public function testTheSidebarAlwaysLinksToTheVehicleList(): void
    {
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('[data-slot=sidebar] a[href="/"][data-active=true]', 'My vehicles');

        $this->client->request('GET', '/settings');
        self::assertSelectorTextContains('[data-slot=sidebar] a[href="/"][data-active=false]', 'My vehicles');
        // No vehicle page, no other links, so nothing to separate.
        self::assertSelectorNotExists('[data-slot=sidebar] [data-slot=sidebar-separator]');
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

    public function testReturnedVehiclesComeLastWithAPillAndAMutedCard(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $owner = self::createUser();
        // By name the returned one would be first.
        $returned = new Vehicle(new Vin('5YJ3E7EB2NF000083'), 'Aardvark', $owner);
        $running = new Vehicle(new Vin('5YJ3E7EB2NF000084'), 'Zebra', $owner);
        $entityManager->persist($returned);
        $entityManager->persist($running);
        $entityManager->persist(new LeaseContract(
            $returned,
            LeaseTerm::fromCalendarDates(
                CarbonImmutable::parse('2020-01-01', 'UTC'),
                CarbonImmutable::parse('2021-01-01', 'UTC'),
                EndDateConvention::Exclusive,
            ),
            Allowance::ofTotal(Distance::fromKilometres(15_000)),
            Distance::fromKilometres(0),
            Money::fromDecimal('0.12', Currency::CHF),
            Tolerance::fromPercent(0),
        ));
        $entityManager->flush();
        self::getContainer()
            ->get(LeaseReturnService::class)
            ->markReturned($returned, new \DateTimeImmutable('2021-01-02'));

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Zebra', 'Aardvark'],
            $this->client->getCrawler()->filter('main h2')->each(static fn ($node): string => $node->text()),
        );
        self::assertSelectorCount(1, '[data-returned-badge]');
        self::assertSelectorTextContains('[data-returned=true] [data-returned-badge]', 'Returned');
        self::assertSelectorNotExists('[data-returned=false] [data-returned-badge]');

        $this->client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);
        self::assertSelectorTextContains('[data-returned-badge]', 'Rendu');
    }
}
