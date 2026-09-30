<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Lease\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Lease\Domain\HalfUp;

#[CoversClass(HalfUp::class)]
final class HalfUpTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function scales(): iterable
    {
        yield 'exact' => [10, 3, 5, 6];
        yield 'below a half' => [11, 45, 1_000, 0]; // 0.495
        yield 'exactly a half' => [50, 1, 100, 1]; // 0.5
        yield 'above a half' => [12, 45, 1_000, 1]; // 0.54
        yield 'zero value' => [0, 45, 1_000, 0];
        yield 'zero numerator' => [5, 0, 1_000, 0];
    }

    #[DataProvider('scales')]
    public function testRoundsHalfUp(int $value, int $numerator, int $denominator, int $expected): void
    {
        self::assertSame($expected, HalfUp::scale($value, $numerator, $denominator));
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function invalid(): iterable
    {
        yield 'negative value' => [-1, 1, 1];
        yield 'negative numerator' => [1, -1, 1];
        yield 'zero denominator' => [1, 1, 0];
        yield 'overflow' => [\PHP_INT_MAX, 2, 1];
    }

    #[DataProvider('invalid')]
    public function testRejectsWhatCannotBeScaled(int $value, int $numerator, int $denominator): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HalfUp::scale($value, $numerator, $denominator);
    }
}
