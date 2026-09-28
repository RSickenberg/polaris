<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Vehicle\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Vehicle\Domain\Vin;

#[CoversClass(Vin::class)]
final class VinTest extends TestCase
{
    public function testValidVin(): void
    {
        self::assertSame('5YJ3E7EB2NF000001', new Vin('5YJ3E7EB2NF000001')->value());
    }

    public function testVinIsNormalized(): void
    {
        self::assertSame('LRW3E7FA1MC000001', new Vin('  lrw3e7fa1mc000001 ')->value());
    }

    public function testEquals(): void
    {
        self::assertTrue(new Vin('XP7YGCEK1PB000001')->equals(new Vin('xp7ygcek1pb000001')));
        self::assertFalse(new Vin('XP7YGCEK1PB000001')->equals(new Vin('XP7YGCEK1PB000002')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidVins(): iterable
    {
        yield 'too short' => ['5YJ3E7EB2NF00000'];
        yield 'too long' => ['5YJ3E7EB2NF0000011'];
        yield 'letter I' => ['5YJ3E7EB2NF00000I'];
        yield 'letter O' => ['5YJ3E7EB2NF00000O'];
        yield 'letter Q' => ['5YJ3E7EB2NF00000Q'];
        yield 'dash' => ['5YJ3E7EB2-F000001'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidVins')]
    public function testInvalidVinIsRejected(string $vin): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Vin($vin);
    }
}
