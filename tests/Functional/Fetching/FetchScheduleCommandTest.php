<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Fetching;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Fetching\Command\FetchScheduleCommand;
use Polaris\Tests\Support\FleetApiResponses;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(FetchScheduleCommand::class)]
final class FetchScheduleCommandTest extends KernelTestCase
{
    use SignsIn;

    public function testFailsWithoutAVehicle(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('No single vehicle found', $tester->getDisplay());
    }

    public function testShowsTheDefaultSlot(): void
    {
        $tester = $this->tester();
        $this->createVehicle();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('at 21:00 (UTC)', $tester->getDisplay());
    }

    public function testSetsOneThenTwoSlots(): void
    {
        $tester = $this->tester();
        $this->createVehicle();

        self::assertSame(Command::SUCCESS, $tester->execute(['first' => '07:30']));
        self::assertStringContainsString('at 07:30 (UTC)', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['first' => '07:30', 'second' => '18:00', '--vin' => strtolower(FleetApiResponses::VIN)]));
        self::assertStringContainsString('at 07:30 and 18:00 (UTC)', $tester->getDisplay());
    }

    public function testRefusesABadTimeAndTwoEqualTimes(): void
    {
        $tester = $this->tester();
        $this->createVehicle();

        self::assertSame(Command::FAILURE, $tester->execute(['first' => '7h30']));
        self::assertStringContainsString('HH:MM', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute(['first' => '07:30', 'second' => '07:30']));
        self::assertStringContainsString('must differ', $tester->getDisplay());
    }

    private function createVehicle(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new Vehicle(new Vin(FleetApiResponses::VIN), 'Model 3', self::createUser()));
        $entityManager->flush();
    }

    private function tester(): CommandTester
    {
        $kernel = self::bootKernel();

        return new CommandTester(new Application($kernel)->find('polaris:fetch:schedule'));
    }
}
