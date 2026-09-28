<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Shared\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\DistanceDelta;

#[CoversClass(DistanceDelta::class)]
final class DistanceDeltaTest extends TestCase
{
    public function testPositiveDelta(): void
    {
        $delta = DistanceDelta::between(Distance::fromMetres(1_000), Distance::fromMetres(1_500));

        self::assertSame(500, $delta->metres());
        self::assertFalse($delta->isNegative());
        self::assertSame(500, $delta->absolute()->metres());
    }

    public function testNegativeDelta(): void
    {
        $delta = DistanceDelta::between(Distance::fromMetres(1_500), Distance::fromMetres(1_000));

        self::assertSame(-500, $delta->metres());
        self::assertTrue($delta->isNegative());
        self::assertSame(500, $delta->absolute()->metres());
    }

    public function testZeroDeltaIsNotNegative(): void
    {
        $delta = DistanceDelta::between(Distance::fromMetres(42), Distance::fromMetres(42));

        self::assertSame(0, $delta->metres());
        self::assertFalse($delta->isNegative());
    }

    public function testEquals(): void
    {
        $delta = DistanceDelta::between(Distance::fromMetres(0), Distance::fromMetres(7));

        self::assertTrue($delta->equals(DistanceDelta::between(Distance::fromMetres(3), Distance::fromMetres(10))));
        self::assertFalse($delta->equals(DistanceDelta::between(Distance::fromMetres(7), Distance::fromMetres(0))));
    }
}
