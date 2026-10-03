<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Lease\Entity;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\Tolerance;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\Money;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;

#[CoversClass(LeaseContract::class)]
final class LeaseContractTest extends TestCase
{
    public function testUpdateTermsOverwritesTheTermsAndKeepsTheIdentity(): void
    {
        $contract = self::contract();
        $id = $contract->getId();
        $vehicle = $contract->getVehicle();

        $term = new LeaseTerm(CarbonImmutable::parse('2027-03-01', 'UTC'), CarbonImmutable::parse('2029-03-01', 'UTC'), EndDateConvention::Exclusive);
        $contract->updateTerms($term, Allowance::ofTotal(Distance::fromKilometres(40_000)), Distance::fromKilometres(500), Money::fromDecimal('0.25', Currency::EUR), Tolerance::fromPercent(5));

        self::assertSame($id, $contract->getId());
        self::assertSame($vehicle, $contract->getVehicle());
        self::assertSame('2027-03-01', $contract->getTerm()->start()->toDateString());
        self::assertSame(24, $contract->getTerm()->months());
        self::assertSame(40_000_000, $contract->getAllowance()->total()->metres());
        self::assertSame(500_000, $contract->getStartOdometer()->metres());
        self::assertSame(25, $contract->getExcessCostPerKm()->minorAmount());
        self::assertSame(Currency::EUR, $contract->getExcessCostPerKm()->currency());
        self::assertSame(500, $contract->getTolerance()->basisPoints());
    }

    public function testUpdateTermsRefusesANegativeExcessCost(): void
    {
        $contract = self::contract();
        $term = $contract->getTerm();

        try {
            $contract->updateTerms($term, Allowance::ofTotal(Distance::fromKilometres(1)), Distance::fromMetres(0), Money::ofMinor(-1, Currency::CHF), Tolerance::fromBasisPoints(0));
            self::fail('A negative cost must be refused.');
        } catch (\InvalidArgumentException) {
            self::assertSame(12, $contract->getExcessCostPerKm()->minorAmount());
        }
    }

    private static function contract(): LeaseContract
    {
        return new LeaseContract(
            new Vehicle(new Vin('5YJ3E7EB2NF000001'), 'Model 3'),
            new LeaseTerm(CarbonImmutable::parse('2026-01-15', 'UTC'), CarbonImmutable::parse('2029-01-15', 'UTC'), EndDateConvention::Exclusive),
            Allowance::ofTotal(Distance::fromKilometres(45_000)),
            Distance::fromKilometres(12),
            Money::fromDecimal('0.12', Currency::CHF),
            Tolerance::fromPercent(2.5),
        );
    }
}
