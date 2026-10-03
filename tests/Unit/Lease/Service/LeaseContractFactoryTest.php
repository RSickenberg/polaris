<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Lease\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Lease\Domain\AllowanceBasis;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Lease\Form\Model\LeaseContractData;
use Polaris\Lease\Service\LeaseContractFactory;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\DistanceUnit;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;

#[CoversClass(LeaseContractFactory::class)]
#[CoversClass(LeaseContract::class)]
final class LeaseContractFactoryTest extends TestCase
{
    public function testCreatesTheContractFromTheEnteredTerms(): void
    {
        $vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000001'), 'Model 3');

        $contract = new LeaseContractFactory()->create($vehicle, self::data());

        self::assertSame($vehicle, $contract->getVehicle());
        self::assertFalse($contract->getId()->equals($vehicle->getId()));
        self::assertSame('2026-01-15', $contract->getTerm()->start()->toDateString());
        self::assertSame('2029-01-14', $contract->getTerm()->end()->toDateString());
        self::assertSame(EndDateConvention::Inclusive, $contract->getTerm()->endDateConvention());
        self::assertSame(36, $contract->getTerm()->months());
        self::assertSame(45_000_000, $contract->getAllowance()->total()->metres());
        self::assertSame(12_000, $contract->getStartOdometer()->metres());
        self::assertSame(12, $contract->getExcessCostPerKm()->minorAmount());
        self::assertSame(Currency::CHF, $contract->getExcessCostPerKm()->currency());
        self::assertSame(250, $contract->getTolerance()->basisPoints());
    }

    public function testATotalAllowanceIsNotScaledByTheTerm(): void
    {
        $data = self::data();
        $data->allowanceBasis = AllowanceBasis::Total;
        $data->allowance = 100_000;

        $terms = new LeaseContractFactory()->terms($data);

        self::assertSame(100_000_000, $terms->allowance->total()->metres());
    }

    public function testIncompleteDataIsRefused(): void
    {
        $data = self::data();
        $data->allowance = null;

        $this->expectException(\InvalidArgumentException::class);

        new LeaseContractFactory()->terms($data);
    }

    public function testConvertsMilesToMetres(): void
    {
        $data = self::data();
        $data->distanceUnit = DistanceUnit::Mile;
        $data->allowance = 10_000;
        $data->startOdometer = 7;

        $contract = new LeaseContractFactory()->create(new Vehicle(new Vin('5YJ3E7EB2NF000001'), 'Model 3'), $data);

        self::assertSame(48_280_320, $contract->getAllowance()->total()->metres());
        self::assertSame(11_265, $contract->getStartOdometer()->metres());
    }

    public function testRefusesIncompleteData(): void
    {
        $data = self::data();
        $data->currency = null;

        $this->expectException(\InvalidArgumentException::class);

        new LeaseContractFactory()->create(new Vehicle(new Vin('5YJ3E7EB2NF000001'), 'Model 3'), $data);
    }

    public function testRefusesANegativeExcessCost(): void
    {
        $data = self::data();
        $data->excessCostPerKm = '-0.12';

        $this->expectException(\InvalidArgumentException::class);

        new LeaseContractFactory()->create(new Vehicle(new Vin('5YJ3E7EB2NF000001'), 'Model 3'), $data);
    }

    private static function data(): LeaseContractData
    {
        $data = new LeaseContractData();
        $data->startDate = new \DateTimeImmutable('2026-01-15');
        $data->endDate = new \DateTimeImmutable('2029-01-14');
        $data->endDateConvention = EndDateConvention::Inclusive;
        $data->allowance = 15_000;
        $data->startOdometer = 12;
        $data->excessCostPerKm = '0.12';
        $data->currency = Currency::CHF;
        $data->tolerancePercent = 2.5;

        return $data;
    }
}
