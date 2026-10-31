<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Alert;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Alert\EventListener\QueueRiskLevelCheck;
use Polaris\Alert\Message\CheckRiskLevel;
use Polaris\Lease\Form\Model\LeaseContractData;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Reading\Form\Model\OdometerReadingData;
use Polaris\Reading\Service\OdometerReadingService;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\DistanceUnit;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Whatever can move the projection queues one check of the risk level of the vehicle.
 */
#[CoversClass(QueueRiskLevelCheck::class)]
final class RiskLevelCheckTriggersTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SignsIn;

    private Vehicle $vehicle;
    private InMemoryTransport $transport;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-07-02 12:00:00 UTC');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000091'), 'Model 3', self::createUser());
        $entityManager->persist($this->vehicle);
        $entityManager->persist(OdometerReading::manual($this->vehicle, CarbonImmutable::parse('2026-07-02 12:00:00', 'UTC'), Distance::fromKilometres(10_000)));
        $entityManager->flush();

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();
        $this->transport = $transport;
    }

    public function testAManualReadingQueuesACheck(): void
    {
        self::mockTime('2026-07-02 14:00:00 UTC');
        self::service()->recordManual($this->vehicle, self::reading('2026-07-02 13:00', 10_100));

        $this->assertOneCheck();
    }

    public function testAReadingFromTheCarQueuesACheck(): void
    {
        self::mockTime('2026-07-02 14:00:00 UTC');
        self::service()->recordFromApi($this->vehicle, CarbonImmutable::parse('2026-07-02 13:00:00', 'UTC'), Distance::fromKilometres(10_100));

        $this->assertOneCheck();
    }

    public function testAConfirmationQueuesACheck(): void
    {
        self::mockTime('2026-07-03 12:00:00 UTC');

        self::service()->recordFromApi($this->vehicle, CarbonImmutable::parse('2026-07-03 12:00:00', 'UTC'), Distance::fromKilometres(10_000));

        $this->assertOneCheck();
    }

    public function testAValueThatChangesNothingQueuesNoCheck(): void
    {
        // The same value at an instant already stored, and a lower value: nothing moves.
        self::service()->recordFromApi($this->vehicle, CarbonImmutable::parse('2026-07-02 12:00:00', 'UTC'), Distance::fromKilometres(10_000));
        self::service()->recordFromApi($this->vehicle, CarbonImmutable::parse('2026-07-02 13:00:00', 'UTC'), Distance::fromKilometres(9_000));

        self::assertCount(0, $this->transport->getSent());
    }

    public function testSavingTheLeaseTermsQueuesACheck(): void
    {
        self::getContainer()->get(LeaseTermsService::class)->save($this->vehicle, self::terms());

        $this->assertOneCheck();
    }

    private function assertOneCheck(): void
    {
        $sent = $this->transport->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(CheckRiskLevel::class, $message);
        self::assertSame($this->vehicle->getId()->toRfc4122(), $message->vehicleId);
        self::assertSame(0, $message->attempt);
    }

    private static function service(): OdometerReadingService
    {
        return self::getContainer()->get(OdometerReadingService::class);
    }

    private static function reading(string $readAtUtc, int $value): OdometerReadingData
    {
        $data = new OdometerReadingData();
        $data->readAt = CarbonImmutable::parse($readAtUtc, 'UTC');
        $data->odometer = $value;
        $data->distanceUnit = DistanceUnit::Kilometre;

        return $data;
    }

    private static function terms(): LeaseContractData
    {
        $data = new LeaseContractData();
        $data->startDate = new \DateTimeImmutable('2026-01-01');
        $data->endDate = new \DateTimeImmutable('2027-01-01');
        $data->allowance = 15_000;
        $data->startOdometer = 0;
        $data->excessCostPerKm = '0.12';
        $data->currency = Currency::CHF;
        $data->tolerancePercent = 0;

        return $data;
    }
}
