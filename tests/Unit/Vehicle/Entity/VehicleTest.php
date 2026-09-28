<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Vehicle\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Component\Uid\Ulid;

#[CoversClass(Vehicle::class)]
final class VehicleTest extends TestCase
{
    public function testGetsAUlidBeforeBeingPersisted(): void
    {
        $first = new Vehicle(new Vin('5YJ3E7EB2NF000001'), 'Model 3');
        $second = new Vehicle(new Vin('5YJ3E7EB2NF000002'), 'Model Y');

        self::assertTrue(Ulid::isValid((string) $first->getId()));
        self::assertFalse($first->getId()->equals($second->getId()));
        // ULIDs sort in creation order.
        self::assertLessThan(0, $first->getId()->compare($second->getId()));
    }

    public function testNameIsTrimmed(): void
    {
        self::assertSame('Model 3', new Vehicle(new Vin('5YJ3E7EB2NF000001'), '  Model 3 ')->getName());
    }

    public function testBlankNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Vehicle(new Vin('5YJ3E7EB2NF000001'), '   ');
    }

    public function testTooLongNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Vehicle(new Vin('5YJ3E7EB2NF000001'), str_repeat('a', Vehicle::NAME_MAX_LENGTH + 1));
    }
}
