<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Polaris\Fixtures\DevSeed;
use Polaris\Lease\Domain\LeaseStatus;
use Polaris\Lease\Domain\NoData;
use Polaris\Lease\Domain\RiskLevel;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Reading\Service\OdometerReadingService;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(DevSeed::class)]
final class DevSeedTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    /**
     * @return iterable<string, array{int}>
     */
    public static function seeds(): iterable
    {
        for ($seed = 1; $seed <= 40; ++$seed) {
            yield 'seed ' . $seed => [$seed];
        }
    }

    /**
     * Whatever the random readings, each sample vehicle keeps its outcome.
     */
    #[DataProvider('seeds')]
    public function testEverySeedKeepsTheOutcomeOfEachVehicle(int $seed): void
    {
        self::mockTime('2026-07-02 12:00:00 UTC');
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);

        new DevSeed(
            $container->get(ClockInterface::class),
            $container->get(UserPasswordHasherInterface::class),
            new Randomizer(new Mt19937($seed)),
        )->load($entityManager);

        $leases = $container->get(LeaseTermsService::class);
        $readings = $container->get(OdometerReadingService::class);
        $outcomes = [];
        foreach ($entityManager->getRepository(Vehicle::class)->findAll() as $vehicle) {
            $assessment = $leases->assess($vehicle);
            $outcomes[$vehicle->getName()] = match (true) {
                null === $assessment => [] === $readings->history($vehicle) ? 'no lease, no reading' : 'no lease',
                $assessment instanceof NoData => 'no data',
                LeaseStatus::Ended === $assessment->status => 'ended ' . $assessment->riskLevel->value,
                default => $assessment->riskLevel->value,
            };
        }
        ksort($outcomes);

        self::assertSame(
            [
                'Cybertruck' => 'no data',
                'Model 3' => RiskLevel::Over->value,
                'Model S' => 'no lease',
                'Model X' => RiskLevel::Watch->value,
                'Model Y' => RiskLevel::Ok->value,
                'Roadster' => 'ended over',
            ],
            $outcomes,
        );
    }

    public function testTwoSeedsGiveDifferentReadings(): void
    {
        self::mockTime('2026-07-02 12:00:00 UTC');
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $seed = static fn (int $number): DevSeed => new DevSeed(
            $container->get(ClockInterface::class),
            $container->get(UserPasswordHasherInterface::class),
            new Randomizer(new Mt19937($number)),
        );

        $seed(1)->load($entityManager);
        $first = $this->odometers($entityManager);
        $entityManager->getConnection()->executeStatement('DELETE FROM odometer_reading');
        $entityManager->getConnection()->executeStatement('DELETE FROM lease_contract');
        $entityManager->getConnection()->executeStatement('DELETE FROM vehicle');
        $entityManager->getConnection()->executeStatement('DELETE FROM app_user');
        $entityManager->clear();
        $seed(2)->load($entityManager);

        self::assertNotSame($first, $this->odometers($entityManager));
    }

    /**
     * @return list<string>
     */
    private function odometers(EntityManagerInterface $entityManager): array
    {
        /** @var list<string> $rows */
        $rows = $entityManager->getConnection()->fetchFirstColumn('SELECT read_at::text || odometer_m FROM odometer_reading ORDER BY read_at');

        return $rows;
    }
}
