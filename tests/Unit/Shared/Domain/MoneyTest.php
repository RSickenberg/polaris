<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Shared\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Money;

#[CoversClass(Money::class)]
#[CoversClass(Currency::class)]
final class MoneyTest extends TestCase
{
    public function testEverySupportedCurrencyHasTwoMinorDigits(): void
    {
        foreach (Currency::cases() as $currency) {
            self::assertSame(2, $currency->minorUnits());
        }
    }

    public function testOfMinor(): void
    {
        $money = Money::ofMinor(12, Currency::CHF);

        self::assertSame(12, $money->minorAmount());
        self::assertSame(Currency::CHF, $money->currency());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function decimalAmounts(): iterable
    {
        yield 'cents' => ['0.12', 12];
        yield 'one decimal' => ['0.5', 50];
        yield 'no decimal' => ['3', 300];
        yield 'zero' => ['0', 0];
        yield 'large' => ['1234.56', 123_456];
        yield 'negative' => ['-3.5', -350];
    }

    #[DataProvider('decimalAmounts')]
    public function testFromDecimal(string $amount, int $minorAmount): void
    {
        self::assertSame($minorAmount, Money::fromDecimal($amount, Currency::EUR)->minorAmount());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDecimalAmounts(): iterable
    {
        yield 'sub-cent' => ['0.085'];
        yield 'comma' => ['0,12'];
        yield 'empty' => [''];
        yield 'trailing dot' => ['1.'];
        yield 'leading dot' => ['.5'];
        yield 'exponent' => ['1e3'];
        yield 'spaces' => [' 1.00'];
        yield 'too many digits' => ['1234567890123456'];
    }

    #[DataProvider('invalidDecimalAmounts')]
    public function testFromDecimalRejectsInvalidAmounts(string $amount): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::fromDecimal($amount, Currency::USD);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function minorAmounts(): iterable
    {
        yield 'cents' => [12, '0.12'];
        yield 'zero' => [0, '0.00'];
        yield 'units' => [123_456, '1234.56'];
        yield 'negative' => [-5, '-0.05'];
    }

    #[DataProvider('minorAmounts')]
    public function testToDecimal(int $minorAmount, string $decimal): void
    {
        self::assertSame($decimal, Money::ofMinor($minorAmount, Currency::GBP)->toDecimal());
    }

    public function testMultiply(): void
    {
        self::assertTrue(Money::ofMinor(12, Currency::CHF)->multiply(1500)->equals(Money::ofMinor(18_000, Currency::CHF)));
    }

    public function testEqualsComparesTheCurrency(): void
    {
        self::assertFalse(Money::ofMinor(12, Currency::CHF)->equals(Money::ofMinor(12, Currency::EUR)));
        self::assertFalse(Money::ofMinor(12, Currency::CHF)->equals(Money::ofMinor(13, Currency::CHF)));
    }

    public function testIsNegative(): void
    {
        self::assertTrue(Money::ofMinor(-1, Currency::CHF)->isNegative());
        self::assertFalse(Money::ofMinor(0, Currency::CHF)->isNegative());
    }
}
