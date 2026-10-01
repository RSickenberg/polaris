<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Shared\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Kernel;
use Polaris\Shared\Domain\TimeZone;

#[CoversClass(TimeZone::class)]
final class TimeZoneTest extends TestCase
{
    public function testUtcIsTheZoneTheKernelForces(): void
    {
        // Read through reflection: both are compile-time constants, which PHPStan folds into a tautology.
        self::assertSame(new \ReflectionClassConstant(Kernel::class, 'TIMEZONE')->getValue(), new \ReflectionClassConstant(TimeZone::class, 'UTC')->getValue());
    }
}
