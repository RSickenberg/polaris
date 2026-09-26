<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(Kernel::class)]
final class KernelBootTest extends KernelTestCase
{
    public function testKernelBoots(): void
    {
        $kernel = self::bootKernel();

        self::assertInstanceOf(Kernel::class, $kernel);
        self::assertSame('test', $kernel->getEnvironment());
    }

    public function testBootForcesUtc(): void
    {
        date_default_timezone_set('Europe/Zurich');

        self::bootKernel();

        self::assertSame('UTC', date_default_timezone_get());
    }
}
