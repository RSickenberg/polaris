<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Kernel;

#[CoversClass(Kernel::class)]
final class KernelFromContextTest extends TestCase
{
    public function testBuildsTheKernelFromTheRuntimeContext(): void
    {
        $kernel = Kernel::fromContext(['APP_ENV' => 'prod', 'APP_DEBUG' => '0']);

        self::assertSame('prod', $kernel->getEnvironment());
        self::assertFalse($kernel->isDebug());
    }

    public function testEnablesDebugFromTheRuntimeContext(): void
    {
        $kernel = Kernel::fromContext(['APP_ENV' => 'dev', 'APP_DEBUG' => '1']);

        self::assertTrue($kernel->isDebug());
    }

    public function testDebugDefaultsToOff(): void
    {
        self::assertFalse(Kernel::fromContext(['APP_ENV' => 'prod'])->isDebug());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidEnvironments(): iterable
    {
        yield 'missing' => [[]];
        yield 'empty' => [['APP_ENV' => '']];
        yield 'not a string' => [['APP_ENV' => 1]];
    }

    /**
     * @param array<string, mixed> $context
     */
    #[DataProvider('invalidEnvironments')]
    public function testRejectsAnInvalidEnvironment(array $context): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('APP_ENV must be a non-empty string.');

        Kernel::fromContext($context);
    }
}
