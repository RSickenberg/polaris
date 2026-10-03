<?php

declare(strict_types=1);

namespace Polaris\Shared\Domain;

/**
 * An amount of money, held as an integer number of minor units (cents, centimes).
 */
final readonly class Money
{
    private function __construct(
        private int $minorAmount,
        private Currency $currency,
    ) {
    }

    public static function ofMinor(int $minorAmount, Currency $currency): self
    {
        return new self($minorAmount, $currency);
    }

    /**
     * Parses a decimal amount such as "0.12" or "-3.5", with at most as many decimals
     * as the currency's minor unit. The string is parsed exactly, without floats.
     */
    public static function fromDecimal(string $amount, Currency $currency): self
    {
        $digits = $currency->minorUnits();

        if (1 !== preg_match('/^(-?)(\d{1,15})(?:\.(\d{1,' . $digits . '}))?$/', $amount, $matches)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid %s amount with at most %d decimals.', $amount, $currency->value, $digits));
        }

        $minorAmount = (int) $matches[2] * 10 ** $digits + (int) str_pad($matches[3] ?? '', $digits, '0');

        return new self('-' === $matches[1] ? -$minorAmount : $minorAmount, $currency);
    }

    public function minorAmount(): int
    {
        return $this->minorAmount;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function isNegative(): bool
    {
        return $this->minorAmount < 0;
    }

    /**
     * The amount as a decimal string with every minor digit, for example "0.12".
     */
    public function toDecimal(): string
    {
        $digits = $this->currency->minorUnits();
        $absolute = str_pad((string) abs($this->minorAmount), $digits + 1, '0', \STR_PAD_LEFT);

        return ($this->isNegative() ? '-' : '')
            . substr($absolute, 0, -$digits) . '.' . substr($absolute, -$digits);
    }

    public function multiply(int $factor): self
    {
        return new self($this->minorAmount * $factor, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->minorAmount === $other->minorAmount && $this->currency === $other->currency;
    }
}
