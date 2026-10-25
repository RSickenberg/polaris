<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Vehicle;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Account\Entity\User;
use Polaris\Tesla\Service\Exception\FleetApiError;
use Polaris\Tesla\Service\Exception\TeslaNotConnected;
use Polaris\Tests\Support\FleetApiResponses;
use Polaris\Tests\Support\SignsIn;
use Polaris\Tests\Support\TeslaFixtures;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Polaris\Vehicle\Service\Dto\ImportCandidate;
use Polaris\Vehicle\Service\TeslaVehicleImporter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(TeslaVehicleImporter::class)]
#[CoversClass(ImportCandidate::class)]
final class TeslaVehicleImporterTest extends KernelTestCase
{
    use SignsIn;
    use TeslaFixtures;

    private User $owner;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->owner = self::createUser();
    }

    public function testListsTheAccountAndMarksTheVehiclesAlreadyAdded(): void
    {
        $this->connect();
        $this->add(FleetApiResponses::VIN, 'Mine');

        $candidates = self::importer()->candidates($this->owner);

        self::assertSame([FleetApiResponses::VIN, FleetApiResponses::OTHER_VIN], array_map(static fn (ImportCandidate $c): string => $c->summary->vin->value(), $candidates));
        self::assertSame([true, false], array_map(static fn (ImportCandidate $c): bool => $c->added, $candidates));
        self::assertSame('Raven', $candidates[1]->name());
    }

    public function testImportsEveryNewVehicleForTheUser(): void
    {
        $this->connect();

        $result = self::importer()->import($this->owner);

        self::assertCount(2, $result->added);
        self::assertSame(0, $result->alreadyAdded);
        $vehicles = self::entityManager()->getRepository(Vehicle::class)->findBy(['owner' => $this->owner], ['name' => 'ASC']);
        self::assertSame(['Lucia', 'Raven'], array_map(static fn (Vehicle $v): string => $v->getName(), $vehicles));
    }

    public function testImportsOnlyTheAskedVinsAndNeverTwiceTheSameOne(): void
    {
        $this->connect(null, 200, 4);

        $first = self::importer()->import($this->owner, [new Vin(FleetApiResponses::OTHER_VIN)]);
        $second = self::importer()->import($this->owner, [new Vin(FleetApiResponses::OTHER_VIN)]);

        self::assertCount(1, $first->added);
        self::assertCount(0, $second->added);
        self::assertSame(1, $second->alreadyAdded);
        self::assertCount(1, self::entityManager()->getRepository(Vehicle::class)->findAll());
    }

    public function testAVinTeslaDoesNotListIsIgnored(): void
    {
        $this->connect();

        $result = self::importer()->import($this->owner, [new Vin('5YJ3E7EB2NF000999')]);

        self::assertSame([], $result->added);
        self::assertSame([], self::entityManager()->getRepository(Vehicle::class)->findAll());
    }

    public function testAVehicleWithoutADisplayNameGetsTheEndOfItsVin(): void
    {
        $this->connect(json_encode(['response' => [['vin' => FleetApiResponses::VIN, 'display_name' => null, 'state' => 'asleep']]], \JSON_THROW_ON_ERROR));

        self::importer()->import($this->owner);

        self::assertSame('Tesla TEST', self::entityManager()->getRepository(Vehicle::class)->findOneBy([])?->getName());
    }

    public function testWithoutATeslaAppNothingIsAsked(): void
    {
        $client = new MockHttpClient([]);
        self::getContainer()->set('http_client', $client);

        try {
            self::importer()->candidates($this->owner);
            self::fail('Expected TeslaNotConnected.');
        } catch (TeslaNotConnected) {
            self::assertSame(0, $client->getRequestsCount());
        }
    }

    public function testATeslaFailureAddsNothing(): void
    {
        $this->connect(null, 500);

        $this->expectException(FleetApiError::class);

        try {
            self::importer()->import($this->owner);
        } finally {
            self::assertSame([], self::entityManager()->getRepository(Vehicle::class)->findAll());
        }
    }

    private function connect(?string $body = null, int $status = 200, int $responses = 1): void
    {
        $app = self::createConfiguredApp($this->owner);
        self::createToken($app, CarbonImmutable::now()->addHours(2));
        self::getContainer()->set('http_client', new MockHttpClient(array_fill(0, $responses, new MockResponse($body ?? FleetApiResponses::vehicleList(), ['http_code' => $status]))));
    }

    private function add(string $vin, string $name): void
    {
        self::entityManager()->persist(new Vehicle(new Vin($vin), $name, $this->owner));
        self::entityManager()->flush();
    }

    private static function importer(): TeslaVehicleImporter
    {
        return self::getContainer()->get(TeslaVehicleImporter::class);
    }

    private static function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
