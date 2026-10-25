<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Vehicle;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Tests\Support\FleetApiResponses;
use Polaris\Tests\Support\SignsIn;
use Polaris\Tests\Support\TeslaFixtures;
use Polaris\Vehicle\Command\ImportVehiclesCommand;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(ImportVehiclesCommand::class)]
final class ImportVehiclesCommandTest extends KernelTestCase
{
    use SignsIn;
    use TeslaFixtures;

    public function testFailsWithoutAUser(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('polaris:user:create', $tester->getDisplay());
    }

    public function testFailsWithoutATeslaConnection(): void
    {
        $tester = $this->tester();
        self::createUser();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Tesla is not connected', $tester->getDisplay());
    }

    public function testDryRunListsAndAddsNothing(): void
    {
        $tester = $this->tester();
        $this->connect();

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));

        self::assertStringContainsString('would be added', $tester->getDisplay());
        self::assertStringContainsString('Raven', $tester->getDisplay());
        self::assertSame([], self::getContainer()->get(EntityManagerInterface::class)->getRepository(Vehicle::class)->findAll());
    }

    public function testImportsAndThenFindsNothingNew(): void
    {
        $tester = $this->tester();
        $this->connect(2);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('2 added, 0 already there', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('0 added, 2 already there', $tester->getDisplay());
    }

    public function testImportsOnlyTheGivenVin(): void
    {
        $tester = $this->tester();
        $this->connect();

        self::assertSame(Command::SUCCESS, $tester->execute(['--vin' => [strtolower(FleetApiResponses::OTHER_VIN)]]));

        $vehicles = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Vehicle::class)->findAll();
        self::assertCount(1, $vehicles);
        self::assertSame(FleetApiResponses::OTHER_VIN, $vehicles[0]->getVin()->value());
    }

    public function testRefusesAMalformedVin(): void
    {
        $tester = $this->tester();
        $this->connect();

        self::assertSame(Command::INVALID, $tester->execute(['--vin' => ['nope']]));
    }

    private function connect(int $responses = 1): void
    {
        $app = self::createConfiguredApp(self::createUser());
        self::createToken($app, CarbonImmutable::now()->addHours(2));
        self::getContainer()->set('http_client', new MockHttpClient(array_fill(0, $responses * 2, new MockResponse(FleetApiResponses::vehicleList()))));
    }

    private function tester(): CommandTester
    {
        $kernel = self::bootKernel();

        return new CommandTester(new Application($kernel)->find('polaris:vehicle:import'));
    }
}
