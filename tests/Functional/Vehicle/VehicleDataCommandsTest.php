<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Vehicle;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Reading\Service\OdometerReadingService;
use Polaris\Shared\Domain\Distance;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Command\ExportVehicleDataCommand;
use Polaris\Vehicle\Command\ImportVehicleDataCommand;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ExportVehicleDataCommand::class)]
#[CoversClass(ImportVehicleDataCommand::class)]
final class VehicleDataCommandsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SignsIn;

    private const string SOURCE_VIN = '5YJ3E7EB2NF000701';
    private const string TARGET_VIN = '5YJ3E7EB2NF000702';

    private EntityManagerInterface $entityManager;
    private Vehicle $source;
    private Vehicle $target;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-07-03 00:05:00 UTC');
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $owner = self::createUser();
        $this->source = new Vehicle(new Vin(self::SOURCE_VIN), 'Source', $owner);
        $this->target = new Vehicle(new Vin(self::TARGET_VIN), 'Target', $owner);
        $this->entityManager->persist($this->source);
        $this->entityManager->persist($this->target);
        $this->entityManager->persist(OdometerReading::manual(
            $this->source,
            CarbonImmutable::parse('2026-06-01 10:00:00', 'UTC'),
            Distance::fromKilometres(10_000),
        ));
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob(sys_get_temp_dir() . '/polaris-test-*.json') ?: []);
        parent::tearDown();
    }

    public function testTheExportIsPrintedAsJsonWithoutTheVin(): void
    {
        $tester = $this->tester('polaris:vehicle:data:export');

        self::assertSame(Command::SUCCESS, $tester->execute(['vin' => strtolower(self::SOURCE_VIN)]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('"format": "polaris.vehicle-data"', $display);
        self::assertStringContainsString('"odometerMetres": 10000000', $display);
        self::assertStringNotContainsString(self::SOURCE_VIN, $display);
    }

    public function testTheExportCanBeWrittenToAFileAndImportedBack(): void
    {
        $file = sys_get_temp_dir() . '/polaris-test-' . bin2hex(random_bytes(6)) . '.json';
        $export = $this->tester('polaris:vehicle:data:export');
        self::assertSame(Command::SUCCESS, $export->execute(['vin' => self::SOURCE_VIN, '--file' => $file]));
        self::assertFileExists($file);

        $import = $this->tester('polaris:vehicle:data:import');
        self::assertSame(Command::SUCCESS, $import->execute(['vin' => self::TARGET_VIN, 'file' => $file]));

        self::assertStringContainsString('1 added, 0 already there, 0 refused', $import->getDisplay());
        $this->entityManager->clear();
        $target = $this->entityManager->find(Vehicle::class, $this->target->getId());
        self::assertInstanceOf(Vehicle::class, $target);
        self::assertCount(1, self::getContainer()->get(OdometerReadingService::class)->history($target));

        self::assertSame(Command::SUCCESS, $import->execute(['vin' => self::TARGET_VIN, 'file' => $file]));
        self::assertStringContainsString('0 added, 1 already there, 0 refused', $import->getDisplay());
    }

    public function testAnUnknownOrInvalidVinIsRefused(): void
    {
        $tester = $this->tester('polaris:vehicle:data:export');

        self::assertSame(Command::FAILURE, $tester->execute(['vin' => '5YJ3E7EB2NF999999']));
        self::assertStringContainsString('No vehicle with this VIN.', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(['vin' => 'nope']));
    }

    public function testAFileThatCannotBeReadOrIsNotAnExportFails(): void
    {
        $tester = $this->tester('polaris:vehicle:data:import');

        $missing = ['vin' => self::TARGET_VIN, 'file' => '/no/such/file.json'];
        self::assertSame(Command::FAILURE, $tester->execute($missing));
        self::assertStringContainsString('cannot be read', $tester->getDisplay());

        $file = sys_get_temp_dir() . '/polaris-test-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($file, '{"hello": "world"}');
        self::assertSame(Command::FAILURE, $tester->execute(['vin' => self::TARGET_VIN, 'file' => $file]));
        self::assertStringContainsString('not an export of Polaris', $tester->getDisplay());
    }

    private function tester(string $name): CommandTester
    {
        return new CommandTester(new Application(self::bootKernel())->find($name));
    }
}
