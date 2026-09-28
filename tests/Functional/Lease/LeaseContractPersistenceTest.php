<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Lease;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\Tolerance;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Shared\Doctrine\Type\CarbonDateImmutableType;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\Money;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(LeaseContract::class)]
#[CoversClass(Vehicle::class)]
#[CoversClass(CarbonDateImmutableType::class)]
final class LeaseContractPersistenceTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        date_default_timezone_set('UTC');
    }

    public function testContractIsReloadedWithUtcCarbonDates(): void
    {
        $entityManager = self::entityManager();

        $vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000001'), 'Model 3');
        $term = new LeaseTerm(CarbonImmutable::parse('2028-02-29', 'UTC'), CarbonImmutable::parse('2031-02-27', 'UTC'), EndDateConvention::Inclusive);
        $contract = new LeaseContract(
            $vehicle,
            $term,
            Allowance::fromYearly(Distance::fromKilometres(15_000), $term),
            Distance::fromMiles(7),
            Money::fromDecimal('0.12', Currency::CHF),
            Tolerance::fromPercent(2.5),
        );

        $entityManager->persist($vehicle);
        $entityManager->persist($contract);
        $entityManager->flush();
        $entityManager->clear();

        // A far-off default time zone must not move the dates on the way back.
        date_default_timezone_set('Pacific/Kiritimati');

        $reloaded = $entityManager->find(LeaseContract::class, $contract->getId());
        self::assertInstanceOf(LeaseContract::class, $reloaded);
        self::assertNotSame($contract, $reloaded);

        $reloadedTerm = $reloaded->getTerm();
        foreach ([$reloadedTerm->start(), $reloadedTerm->end()] as $date) {
            self::assertSame(CarbonImmutable::class, $date::class);
            self::assertSame('UTC', $date->getTimezone()->getName());
            self::assertSame('00:00:00', $date->format('H:i:s'));
        }
        self::assertSame('2028-02-29', $reloadedTerm->start()->toDateString());
        self::assertSame('2031-02-27', $reloadedTerm->end()->toDateString());
        self::assertSame(EndDateConvention::Inclusive, $reloadedTerm->endDateConvention());
        self::assertSame(36, $reloadedTerm->months());

        self::assertSame(45_000_000, $reloaded->getAllowance()->total()->metres());
        self::assertSame(11_265, $reloaded->getStartOdometer()->metres());
        self::assertTrue($reloaded->getExcessCostPerKm()->equals(Money::ofMinor(12, Currency::CHF)));
        self::assertSame(250, $reloaded->getTolerance()->basisPoints());
        self::assertSame('5YJ3E7EB2NF000001', $reloaded->getVehicle()->getVin()->value());
        self::assertSame('Model 3', $reloaded->getVehicle()->getName());
    }

    public function testDatesAreStoredAsCalendarDates(): void
    {
        $entityManager = self::entityManager();

        $vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000002'), 'Model Y');
        $term = new LeaseTerm(CarbonImmutable::parse('2026-01-15', 'UTC'), CarbonImmutable::parse('2029-01-15', 'UTC'), EndDateConvention::Exclusive);
        $entityManager->persist($vehicle);
        $entityManager->persist(new LeaseContract($vehicle, $term, Allowance::fromYearly(Distance::fromKilometres(10_000), $term), Distance::fromMetres(0), Money::ofMinor(0, Currency::EUR), Tolerance::fromBasisPoints(0)));
        $entityManager->flush();

        $row = $entityManager->getConnection()->fetchAssociative(
            'SELECT start_date, end_date, pg_typeof(start_date)::text AS start_type FROM lease_contract WHERE vehicle_id = ?',
            [$vehicle->getId()],
        );

        self::assertSame(['start_date' => '2026-01-15', 'end_date' => '2029-01-15', 'start_type' => 'date'], $row);
    }

    public function testVinIsUnique(): void
    {
        $entityManager = self::entityManager();
        $entityManager->persist(new Vehicle(new Vin('5YJ3E7EB2NF000003'), 'First'));
        $entityManager->persist(new Vehicle(new Vin('5YJ3E7EB2NF000003'), 'Second'));

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        $entityManager->flush();
    }

    private static function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
