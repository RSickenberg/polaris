<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Reading;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Reading\Command\AddOdometerReadingCommand;
use Polaris\Reading\Repository\OdometerReadingRepository;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(AddOdometerReadingCommand::class)]
final class AddOdometerReadingCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-10-20 12:00:00 UTC');
        $this->vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000020'), 'Model 3');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($this->vehicle);
        $entityManager->flush();
    }

    public function testFirstReadingUsesTheOnlyVehicleAndNow(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['odometer' => '12345']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Recorded 12345 km for Model 3 at 2026-10-20T12:00Z UTC', $tester->getDisplay());
        $readings = self::getContainer()->get(OdometerReadingRepository::class)->findAll();
        self::assertCount(1, $readings);
        self::assertSame(12_345_000, $readings[0]->getOdometer()->metres());
    }

    public function testMilesAndExplicitInstantWithOffsetAreStoredInMetresAndUtc(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['odometer' => '1000', '--unit' => 'mi', '--at' => '2026-10-01T10:00:00+02:00', '--vehicle' => '5yj3e7eb2nf000020']);

        self::assertSame(Command::SUCCESS, $status);
        $reading = self::getContainer()->get(OdometerReadingRepository::class)->findAll()[0];
        self::assertSame(1_609_344, $reading->getOdometer()->metres());
        self::assertSame('2026-10-01 08:00:00', $reading->getReadAt()->format('Y-m-d H:i:s'));
    }

    public function testVehicleCanBeGivenByUlid(): void
    {
        $status = $this->tester()->execute(['odometer' => '10', '--vehicle' => $this->vehicle->getId()->toBase32()]);

        self::assertSame(Command::SUCCESS, $status);
    }

    public function testLowerReadingFailsWithATranslatedMessage(): void
    {
        $tester = $this->tester();
        $tester->execute(['odometer' => '10000', '--at' => '2026-10-01T08:00:00']);

        $status = $tester->execute(['odometer' => '9999', '--at' => '2026-10-02T08:00:00']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('The odometer cannot be lower than the previous reading (10,000 km on Oct 1, 2026 at 8:00 AM UTC).', preg_replace('/\s+/u', ' ', str_replace("\u{202f}", ' ', $tester->getDisplay())) ?? '');
        self::assertCount(1, self::getContainer()->get(OdometerReadingRepository::class)->findAll());
    }

    public function testEqualReadingIsAccepted(): void
    {
        $tester = $this->tester();
        $tester->execute(['odometer' => '10000', '--at' => '2026-10-01T08:00:00']);

        self::assertSame(Command::SUCCESS, $tester->execute(['odometer' => '10000', '--at' => '2026-10-02T08:00:00']));
    }

    public function testDuplicateInstantFails(): void
    {
        $tester = $this->tester();
        $tester->execute(['odometer' => '10000', '--at' => '2026-10-01T08:00:00']);

        self::assertSame(Command::FAILURE, $tester->execute(['odometer' => '10100', '--at' => '2026-10-01T08:00:00']));
        self::assertStringContainsString('A reading already exists at this date and time.', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }

    public function testInvalidInputIsInvalid(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['odometer' => '-5']), 'a negative value');
        self::assertSame(Command::INVALID, $tester->execute(['odometer' => '5', '--unit' => 'furlong']), 'an unknown unit');
        self::assertSame(Command::INVALID, $tester->execute(['odometer' => '5', '--at' => 'not a date']), 'an unparsable date');
        self::assertSame(Command::INVALID, $tester->execute(['odometer' => '5', '--vehicle' => '5YJ3E7EB2NF999999']), 'an unknown vehicle');
        self::assertCount(0, self::getContainer()->get(OdometerReadingRepository::class)->findAll());
    }

    public function testSeveralVehiclesRequireTheOption(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new Vehicle(new Vin('5YJ3E7EB2NF000021'), 'Model Y'));
        $entityManager->flush();

        self::assertSame(Command::INVALID, $this->tester()->execute(['odometer' => '5']));
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('polaris:odometer:add'));
    }
}
