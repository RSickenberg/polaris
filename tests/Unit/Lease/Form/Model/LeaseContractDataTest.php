<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Lease\Form\Model;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\AllowanceBasis;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\Tolerance;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Lease\Form\Model\LeaseContractData;
use Polaris\Lease\Service\LeaseContractFactory;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\DistanceUnit;
use Polaris\Shared\Domain\Money;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;

#[CoversClass(LeaseContractData::class)]
final class LeaseContractDataTest extends TestCase
{
    public function testAnExactYearlyAllowanceIsShownPerYearInKilometres(): void
    {
        $data = LeaseContractData::fromContract(self::contract(Distance::fromKilometres(45_000)));

        self::assertSame(AllowanceBasis::PerYear, $data->allowanceBasis);
        self::assertSame(DistanceUnit::Kilometre, $data->distanceUnit);
        self::assertEquals(15_000, $data->allowance);
        self::assertEquals(12, $data->startOdometer);
        self::assertSame('0.12', $data->excessCostPerKm);
        self::assertSame(Currency::CHF, $data->currency);
        self::assertEquals(2.5, $data->tolerancePercent);
        self::assertSame('2026-01-15', $data->startDate?->format('Y-m-d'));
        self::assertSame('2029-01-15', $data->endDate?->format('Y-m-d'));
        self::assertSame(EndDateConvention::Exclusive, $data->endDateConvention);
    }

    public function testATotalWithoutAnExactYearlyDistanceIsShownAsTotal(): void
    {
        // 36 months: 100,000.001 km x 12 / 36 is not a whole number of metres.
        $contract = self::contract(Distance::fromMetres(100_000_001));

        $data = LeaseContractData::fromContract($contract);

        self::assertSame(AllowanceBasis::Total, $data->allowanceBasis);
        self::assertEquals(100_000.001, $data->allowance);
    }

    public function testSavingTheShownDataUnchangedKeepsTheStoredTerms(): void
    {
        foreach ([Distance::fromKilometres(45_000), Distance::fromMetres(100_000_001), Distance::fromMiles(30_000)] as $total) {
            $contract = self::contract($total);

            $terms = new LeaseContractFactory()->terms(LeaseContractData::fromContract($contract));

            self::assertSame($total->metres(), $terms->allowance->total()->metres());
            self::assertSame(12_000, $terms->startOdometer->metres());
            self::assertSame(250, $terms->tolerance->basisPoints());
        }
    }

    private static function contract(Distance $total): LeaseContract
    {
        return new LeaseContract(
            new Vehicle(new Vin('5YJ3E7EB2NF000001'), 'Model 3'),
            new LeaseTerm(CarbonImmutable::parse('2026-01-15', 'UTC'), CarbonImmutable::parse('2029-01-15', 'UTC'), EndDateConvention::Exclusive),
            Allowance::ofTotal($total),
            Distance::fromKilometres(12),
            Money::fromDecimal('0.12', Currency::CHF),
            Tolerance::fromPercent(2.5),
        );
    }
}
