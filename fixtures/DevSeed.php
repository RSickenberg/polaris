<?php

declare(strict_types=1);

namespace Polaris\Fixtures;

use Carbon\CarbonImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\Tolerance;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\DistanceUnit;
use Polaris\Shared\Domain\Money;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;

/**
 * Development data to try the app by hand (`make seed`). Dates are relative to now, so the
 * projections stay meaningful whenever it is loaded. The VINs are fake.
 *
 * - Model 3: 36-month lease, 15 000 km a year, driven faster than the allowance (a warning).
 * - Model Y: 24-month lease in miles, driven below the allowance, Tesla readings in miles.
 * - Cybertruck: a lease and no reading yet.
 * - Model S: readings and no lease.
 */
final class DevSeed extends Fixture
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $today = CarbonImmutable::instance($this->clock->now())->startOfDay();

        $modelThree = new Vehicle(new Vin('5YJ3E7EB2NF000001'), 'Model 3');
        $manager->persist($modelThree);
        $manager->persist($this->lease($modelThree, $today->subMonths(14), 36, Allowance::ofTotal(Distance::fromKilometres(45_000)), 12, 5));
        for ($month = 0; $month <= 14; ++$month) {
            $manager->persist(OdometerReading::manual(
                $modelThree,
                $today->subMonths(14 - $month)->setTime(8, 0),
                Distance::fromKilometres(12 + $month * 1_450 + ($month % 3) * 40),
            ));
        }

        $modelY = new Vehicle(new Vin('7SAYGDEE9NF000002'), 'Model Y');
        $manager->persist($modelY);
        $manager->persist($this->lease($modelY, $today->subMonths(6), 24, Allowance::ofTotal(Distance::fromMiles(20_000)), 5, 0));
        for ($month = 0; $month <= 6; ++$month) {
            $manager->persist(OdometerReading::fromTesla(
                $modelY,
                $today->subMonths(6 - $month)->setTime(7, 30),
                (string) (5 + $month * 480 + ($month % 2) * 15),
                DistanceUnit::Mile,
            ));
        }

        $cybertruck = new Vehicle(new Vin('7G2CEHED0RA000003'), 'Cybertruck');
        $manager->persist($cybertruck);
        $manager->persist($this->lease($cybertruck, $today->subMonths(1), 36, Allowance::ofTotal(Distance::fromKilometres(60_000)), 0, 5));

        $modelS = new Vehicle(new Vin('5YJSA1E26NF000004'), 'Model S');
        $manager->persist($modelS);
        foreach ([0, 1, 2] as $step) {
            $manager->persist(OdometerReading::manual(
                $modelS,
                $today->subMonths(2 - $step)->setTime(18, 0),
                Distance::fromKilometres(30_000 + $step * 900),
            ));
        }

        $manager->flush();
    }

    private function lease(Vehicle $vehicle, CarbonImmutable $start, int $months, Allowance $allowance, int $startKm, int $tolerancePercent): LeaseContract
    {
        $term = LeaseTerm::fromCalendarDates($start, $start->addMonthsNoOverflow($months), EndDateConvention::Exclusive);

        return new LeaseContract(
            $vehicle,
            $term,
            $allowance,
            Distance::fromKilometres($startKm),
            Money::fromDecimal('0.15', Currency::EUR),
            Tolerance::fromPercent($tolerancePercent),
        );
    }
}
