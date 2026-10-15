<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Vehicle;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Polaris\Vehicle\Security\VehicleVoter;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A user reaches only the vehicles they own; another user's vehicle is not found.
 */
#[CoversClass(VehicleVoter::class)]
final class VehicleOwnershipTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;
    private Vehicle $mine;
    private Vehicle $theirs;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $me = self::createUser('me@example.test');
        $other = self::createUser('other@example.test');
        $this->mine = new Vehicle(new Vin('5YJ3E7EB2NF000101'), 'Mine', $me);
        $this->theirs = new Vehicle(new Vin('5YJ3E7EB2NF000102'), 'Aardvark theirs', $other);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($this->mine);
        $entityManager->persist($this->theirs);
        $entityManager->flush();
        self::signIn($this->client, $me);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pages(): iterable
    {
        yield 'dashboard' => ['GET', ''];
        yield 'readings' => ['GET', '/readings'];
        yield 'add a reading' => ['POST', '/readings'];
        yield 'lease' => ['GET', '/lease'];
        yield 'save the lease' => ['POST', '/lease'];
    }

    #[DataProvider('pages')]
    public function testAnotherUsersVehicleIsNotFound(string $method, string $suffix): void
    {
        $this->client->request($method, '/vehicles/' . $this->theirs->getId()->toBase32() . $suffix);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ownPages(): iterable
    {
        yield 'dashboard' => [''];
        yield 'readings' => ['/readings'];
        yield 'lease' => ['/lease'];
    }

    #[DataProvider('ownPages')]
    public function testTheOwnVehicleIsReachable(string $suffix): void
    {
        $this->client->request('GET', '/vehicles/' . $this->mine->getId()->toBase32() . $suffix);

        self::assertResponseIsSuccessful();
    }

    public function testTheLandingPageNeverPicksAnotherUsersVehicle(): void
    {
        // "Aardvark theirs" sorts first by name, but it is not mine.
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/vehicles/' . $this->mine->getId()->toBase32());
    }

    public function testTheLandingPageIsEmptyWhenTheUserOwnsNothing(): void
    {
        self::signIn($this->client, self::createUser('nobody@example.test'));

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'There is no vehicle yet.');
    }
}
