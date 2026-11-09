<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Vehicle;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Account\Entity\User;
use Polaris\Extension\SingleVehicleLimitPolicy;
use Polaris\Tests\Support\FleetApiResponses;
use Polaris\Tests\Support\LiftsVehicleLimit;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Polaris\Vehicle\Service\Exception\VehicleLimitReached;
use Polaris\Vehicle\Service\VehicleQuota;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(VehicleQuota::class)]
#[CoversClass(SingleVehicleLimitPolicy::class)]
#[CoversClass(VehicleLimitReached::class)]
final class VehicleQuotaTest extends KernelTestCase
{
    use LiftsVehicleLimit;
    use SignsIn;

    public function testSelfHostedDefaultAllowsASingleVehicle(): void
    {
        self::bootKernel();
        $owner = self::createUser();

        self::assertSame(1, self::quota()->remaining($owner));
        self::quota()->assertCanAdd($owner);

        $this->add($owner, FleetApiResponses::VIN);

        self::assertSame(0, self::quota()->remaining($owner));
        $this->expectException(VehicleLimitReached::class);
        self::quota()->assertCanAdd($owner);
    }

    public function testTheLimitIsPerOwner(): void
    {
        self::bootKernel();
        $first = self::createUser();
        $second = self::createUser('other@example.test');
        $this->add($first, FleetApiResponses::VIN);

        self::assertSame(1, self::quota()->remaining($second));
    }

    public function testSeveralVehiclesAtOnceAreCountedTogether(): void
    {
        self::bootKernel();
        $owner = self::createUser();

        $this->expectException(VehicleLimitReached::class);

        self::quota()->assertCanAdd($owner, 2);
    }

    public function testAReplacedPolicyWithoutALimitHasNoRemainingCount(): void
    {
        self::bootKernel();
        $this->liftVehicleLimit();
        $owner = self::createUser();
        $this->add($owner, FleetApiResponses::VIN);

        self::assertNull(self::quota()->remaining($owner));
        self::quota()->assertCanAdd($owner, 10);
    }

    private function add(User $owner, string $vin): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new Vehicle(new Vin($vin), 'Car', $owner));
        $entityManager->flush();
    }

    private static function quota(): VehicleQuota
    {
        return self::getContainer()->get(VehicleQuota::class);
    }
}
