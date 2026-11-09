<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Shared\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Shared\Domain\NumberConvention;

#[CoversClass(NumberConvention::class)]
final class NumberConventionTest extends TestCase
{
    public function testFrenchIsWrittenTheSwissWay(): void
    {
        $convention = NumberConvention::of('fr');

        self::assertSame('fr_CH', $convention->locale);
        self::assertSame('fr-CH', $convention->languageTag());
        self::assertSame('’', $convention->groupingSeparator);
        self::assertSame('12’345,6 km', $convention->group("12\u{202F}345,6 km"));
        self::assertSame('1’234’567', $convention->group("1\u{202F}234\u{00A0}567"));
    }

    public function testOtherLanguagesKeepTheirLocaleAndGrouping(): void
    {
        $convention = NumberConvention::of('en');

        self::assertSame('en', $convention->locale);
        self::assertNull($convention->groupingSeparator);
        self::assertSame('12,345', $convention->group('12,345'));
    }

    public function testOnlyASpaceInsideAFigureIsReplaced(): void
    {
        $convention = NumberConvention::of('fr');

        self::assertSame(
            '1 oct. 2026, 12’000 km en 90 secondes',
            $convention->group("1 oct. 2026, 12\u{202F}000 km en 90 secondes"),
        );
    }
}
