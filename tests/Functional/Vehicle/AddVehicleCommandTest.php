<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Vehicle;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Tests\Support\FleetApiResponses;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Command\AddVehicleCommand;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(AddVehicleCommand::class)]
final class AddVehicleCommandTest extends KernelTestCase
{
    use SignsIn;

    public function testFailsWithoutAUser(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['vin' => FleetApiResponses::VIN, 'name' => 'Model 3']));
        self::assertStringContainsString('polaris:user:create', $tester->getDisplay());
    }

    public function testAddsTheVehicleToTheOnlyUser(): void
    {
        $tester = $this->tester();
        $owner = self::createUser();

        self::assertSame(Command::SUCCESS, $tester->execute(['vin' => strtolower(FleetApiResponses::VIN), 'name' => ' Model 3 ']));

        $vehicle = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Vehicle::class)->findOneBy([]);
        self::assertInstanceOf(Vehicle::class, $vehicle);
        self::assertSame(FleetApiResponses::VIN, $vehicle->getVin()->value());
        self::assertSame('Model 3', $vehicle->getName());
        self::assertSame($owner->getId()->toRfc4122(), $vehicle->getOwner()->getId()->toRfc4122());
    }

    public function testTheOwnerCanBeChosenByEmail(): void
    {
        $tester = $this->tester();
        self::createUser();
        $other = self::createUser('other@example.test');

        self::assertSame(Command::SUCCESS, $tester->execute(['vin' => FleetApiResponses::VIN, 'name' => 'Model 3', '--email' => 'OTHER@example.test']));

        $vehicle = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Vehicle::class)->findOneBy([]);
        self::assertSame($other->getId()->toRfc4122(), $vehicle?->getOwner()->getId()->toRfc4122());
    }

    public function testSeveralUsersNeedAnEmail(): void
    {
        $tester = $this->tester();
        self::createUser();
        self::createUser('other@example.test');

        self::assertSame(Command::FAILURE, $tester->execute(['vin' => FleetApiResponses::VIN, 'name' => 'Model 3']));
    }

    public function testRefusesABadVinABlankNameAndADuplicate(): void
    {
        $tester = $this->tester();
        self::createUser();

        self::assertSame(Command::INVALID, $tester->execute(['vin' => 'NOT-A-VIN', 'name' => 'Model 3']));
        self::assertStringContainsString('not a valid VIN', $tester->getDisplay());

        self::assertSame(Command::INVALID, $tester->execute(['vin' => FleetApiResponses::VIN, 'name' => '  ']));

        self::assertSame(Command::SUCCESS, $tester->execute(['vin' => FleetApiResponses::VIN, 'name' => 'Model 3']));
        self::assertSame(Command::FAILURE, $tester->execute(['vin' => FleetApiResponses::VIN, 'name' => 'Again']));
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $kernel = self::bootKernel();

        return new CommandTester(new Application($kernel)->find('polaris:vehicle:add'));
    }
}
