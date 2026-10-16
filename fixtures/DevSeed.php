<?php

declare(strict_types=1);

namespace Polaris\Fixtures;

use Carbon\CarbonImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Polaris\Account\Entity\User;
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
use Random\Randomizer;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Development data to try the app by hand (`make seed`), owned by the development user. Dates are relative to now, so the
 * projections stay meaningful whenever it is loaded, and every load differs: lease starts, the number and the dates of the
 * readings and the distance between two of them are random. What stays fixed is each vehicle's outcome. The VINs are fake.
 *
 * The distance driven is chosen from a ratio of the allowance and the readings follow that pace, with irregular gaps and
 * noise between two readings, except at the instant 30 days before the last reading, where they sit on the straight line.
 * So the recent pace equals the overall one and the projected end is the ratio of the allowance, whatever the noise.
 *
 * - Model 3: 36-month lease, 15 000 km a year, projected 12 to 35 % over the allowance (over).
 * - Model Y: 24-month lease in miles, projected 55 to 90 % of the allowance, Tesla readings in miles (ok).
 * - Cybertruck: a lease and no reading yet (no data).
 * - Model S: readings and no lease.
 * - Model X: 24-month lease with a 10 % tolerance, projected 93 to 97 % of the allowance (watch).
 * - Roadster: 12-month lease that ended 2 to 8 months ago, 300 to 2 500 km over its allowance (ended).
 */
final class DevSeed extends Fixture
{
    private const int RECENT_WINDOW_DAYS = 30;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly Randomizer $random = new Randomizer(),
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = CarbonImmutable::instance($this->clock->now());
        $today = $now->startOfDay();

        // Its password is random and unknown: `make seed` sets the one it prints (polaris:dev:user).
        $owner = new User(SeedUserCommand::EMAIL, '', $now);
        $owner->changePassword($this->hasher->hashPassword($owner, bin2hex(random_bytes(12))));
        $manager->persist($owner);

        $modelThree = new Vehicle(new Vin('5YJ3E7EB2NF000001'), 'Model 3', $owner);
        $manager->persist($modelThree);
        $start = $today->subMonths($this->random->getInt(10, 18));
        $startKm = $this->random->getInt(0, 40);
        $contract = $this->lease($modelThree, $start, 36, Allowance::ofTotal(Distance::fromKilometres(45_000)), Distance::fromKilometres($startKm), 5);
        $manager->persist($contract);
        foreach ($this->readings($contract, $now, 45_000, $this->ratio(1.12, 1.35), true) as [$at, $driven]) {
            $manager->persist(OdometerReading::manual($modelThree, $at, Distance::fromKilometres($startKm + $driven)));
        }

        $modelY = new Vehicle(new Vin('7SAYGDEE9NF000002'), 'Model Y', $owner);
        $manager->persist($modelY);
        $start = $today->subMonths($this->random->getInt(4, 9));
        $startMiles = $this->random->getInt(0, 30);
        $contract = $this->lease($modelY, $start, 24, Allowance::ofTotal(Distance::fromMiles(20_000)), Distance::fromMiles($startMiles), 0);
        $manager->persist($contract);
        foreach ($this->readings($contract, $now, 20_000, $this->ratio(0.55, 0.9), true) as [$at, $driven]) {
            $manager->persist(OdometerReading::fromTesla($modelY, $at, (string) ($startMiles + $driven), DistanceUnit::Mile));
        }

        $cybertruck = new Vehicle(new Vin('7G2CEHED0RA000003'), 'Cybertruck', $owner);
        $manager->persist($cybertruck);
        $manager->persist($this->lease($cybertruck, $today->subMonths($this->random->getInt(1, 3)), 36, Allowance::ofTotal(Distance::fromKilometres(60_000)), Distance::fromKilometres(0), 5));

        $modelS = new Vehicle(new Vin('5YJSA1E26NF000004'), 'Model S', $owner);
        $manager->persist($modelS);
        $odometer = $this->random->getInt(15_000, 60_000);
        $at = $now->subMonths($this->random->getInt(2, 6));
        for ($count = $this->random->getInt(3, 9); $count > 0; --$count) {
            $at = $this->instantAfter($at, $now->subHours(2));
            $odometer += $this->random->getInt(300, 1_800);
            $manager->persist(OdometerReading::manual($modelS, $at, Distance::fromKilometres($odometer)));
        }

        $modelX = new Vehicle(new Vin('7SAXCBE69PF000005'), 'Model X', $owner);
        $manager->persist($modelX);
        $start = $today->subMonths($this->random->getInt(9, 15));
        $startKm = $this->random->getInt(0, 40);
        $contract = $this->lease($modelX, $start, 24, Allowance::ofTotal(Distance::fromKilometres(30_000)), Distance::fromKilometres($startKm), 10);
        $manager->persist($contract);
        foreach ($this->readings($contract, $now, 30_000, $this->ratio(0.93, 0.97), true) as [$at, $driven]) {
            $manager->persist(OdometerReading::manual($modelX, $at, Distance::fromKilometres($startKm + $driven)));
        }

        $roadster = new Vehicle(new Vin('5YJRE1A31A0000006'), 'Roadster', $owner);
        $manager->persist($roadster);
        $start = $today->subMonths(12 + $this->random->getInt(2, 8));
        $startKm = $this->random->getInt(0, 40);
        $contract = $this->lease($roadster, $start, 12, Allowance::ofTotal(Distance::fromKilometres(15_000)), Distance::fromKilometres($startKm), 0);
        $manager->persist($contract);
        foreach ($this->readings($contract, $now, 15_000, null, false, 15_000 + $this->random->getInt(300, 2_500)) as [$at, $driven]) {
            $manager->persist(OdometerReading::manual($roadster, $at, Distance::fromKilometres($startKm + $driven)));
        }

        $manager->flush();
    }

    /**
     * The readings of a lease: irregular instants after the lease start, each with the distance driven since it began (in
     * the unit of the allowance, whole numbers), ending in the past.
     *
     * With a ratio the total follows the pace that projects to that share of the allowance, and the readings sit on the
     * straight line 30 days before the last one. Without it the total is given and the last reading follows the lease end.
     *
     * @param int      $allowance the whole allowance, in the unit of the readings
     * @param int|null $total     the distance driven at the last reading, when no ratio gives it
     * @param bool     $running   whether the lease is still running (the last reading is a few hours or days old)
     *
     * @return list<array{CarbonImmutable, int}>
     */
    private function readings(LeaseContract $contract, CarbonImmutable $now, int $allowance, ?float $ratio, bool $running, ?int $total = null): array
    {
        $start = $contract->getTerm()->start();
        $end = $contract->getTerm()->exclusiveEnd();

        $last = $running
            ? $now->subSeconds($this->random->getInt(2 * 3_600, 70 * 3_600))
            : $this->instantAfter($end, $now->subHours(2));
        $elapsed = $last->getTimestamp() - $start->getTimestamp();
        $term = $end->getTimestamp() - $start->getTimestamp();
        $total ??= (int) round((float) $ratio * $allowance * $elapsed / $term);

        $anchor = $running && $elapsed > 2 * self::RECENT_WINDOW_DAYS * 86_400 ? $last->subDays(self::RECENT_WINDOW_DAYS) : null;
        $instants = [$last->getTimestamp() => $last];
        if (null !== $anchor) {
            $instants[$anchor->getTimestamp()] = $anchor;
        }
        for ($count = $this->random->getInt(9, 26); $count > 0; --$count) {
            $at = $this->instantBetween($start->addDay(), $last);
            $instants[$at->getTimestamp()] = $at;
        }
        ksort($instants);

        // Each reading weighs the time since the one before it, with noise: the faster stretches drive more.
        $weights = [];
        $previous = $start;
        foreach ($instants as $at) {
            $weights[] = [$at, ($at->getTimestamp() - $previous->getTimestamp()) * (0.7 + 0.6 * $this->unit())];
            $previous = $at;
        }

        // The readings up to the anchor share out the part of the total that the straight line gives it, the rest share out
        // what is left, so the anchor itself sits on the line.
        $groups = [$weights];
        $parts = [$total];
        if (null !== $anchor) {
            $groups = [
                array_values(array_filter($weights, static fn (array $weight): bool => $weight[0] <= $anchor)),
                array_values(array_filter($weights, static fn (array $weight): bool => $weight[0] > $anchor)),
            ];
            $onTheLine = (int) round($total * ($anchor->getTimestamp() - $start->getTimestamp()) / $elapsed);
            $parts = [$onTheLine, $total - $onTheLine];
        }

        $readings = [];
        $driven = 0;
        foreach ($groups as $index => $group) {
            $sum = array_sum(array_column($group, 1));
            $progress = 0.0;
            foreach ($group as [$at, $weight]) {
                $progress += $weight;
                $readings[] = [$at, $driven + (int) round($parts[$index] * $progress / $sum)];
            }
            $driven += $parts[$index];
        }

        return $readings;
    }

    /** A share in [min, max], to a thousandth. */
    private function ratio(float $min, float $max): float
    {
        return $min + ($max - $min) * $this->unit();
    }

    /** A random float in [0, 1]. */
    private function unit(): float
    {
        return $this->random->getInt(0, 1_000_000) / 1_000_000;
    }

    /** A random instant in (from, to], at a plausible hour of the day (06:00 to 22:59) when the day allows it. */
    private function instantBetween(CarbonImmutable $from, CarbonImmutable $to): CarbonImmutable
    {
        $days = max(0, (int) $from->startOfDay()->diffInDays($to->startOfDay()));
        $at = $from->startOfDay()
            ->addDays($this->random->getInt(0, $days))
            ->addSeconds($this->random->getInt(6 * 3_600, 23 * 3_600 - 1));

        return $at->max($from->addMinute())->min($to);
    }

    /** A random instant between $after and $before, a few days after the first at most. */
    private function instantAfter(CarbonImmutable $after, CarbonImmutable $before): CarbonImmutable
    {
        return $this->instantBetween($after->addMinute(), $after->addDays($this->random->getInt(1, 6))->min($before));
    }

    private function lease(Vehicle $vehicle, CarbonImmutable $start, int $months, Allowance $allowance, Distance $startOdometer, int $tolerancePercent): LeaseContract
    {
        $term = LeaseTerm::fromCalendarDates($start, $start->addMonthsNoOverflow($months), EndDateConvention::Exclusive);

        return new LeaseContract(
            $vehicle,
            $term,
            $allowance,
            $startOdometer,
            Money::fromDecimal('0.15', Currency::EUR),
            Tolerance::fromPercent($tolerancePercent),
        );
    }
}
