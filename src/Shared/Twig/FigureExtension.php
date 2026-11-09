<?php

declare(strict_types=1);

namespace Polaris\Shared\Twig;

use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\DistanceDelta;
use Polaris\Shared\Domain\Money;
use Polaris\Shared\Domain\NumberConvention;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Writes figures the way {@see NumberConvention} says for the active locale: in French, 12’345 km, 0.12 CHF and
 * 45%. Every figure of a page goes through these filters, so the same value looks the same everywhere.
 *
 * - `figure(maxDecimals = 0)`: a number.
 * - `km(maxDecimals = 0)`: a {@see Distance} or {@see DistanceDelta} in kilometres, without the unit.
 * - `money`: a {@see Money}, with its currency.
 * - `amount(currency)`: a decimal amount in a currency (ISO 4217 code).
 * - `percent`: a share (0.45 is 45%).
 * - `number_convention()`: the convention itself, for the browser (the chart formats in JavaScript).
 */
final class FigureExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('figure', $this->figure(...)),
            new TwigFilter('km', $this->kilometres(...)),
            new TwigFilter('money', $this->money(...)),
            new TwigFilter('amount', $this->amount(...)),
            new TwigFilter('percent', $this->percent(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('number_convention', $this->convention(...)),
        ];
    }

    public function figure(int|float $value, int $maxDecimals = 0): string
    {
        $formatter = new \NumberFormatter($this->convention()->locale, \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $maxDecimals);

        return $this->convention()->group((string) $formatter->format($value));
    }

    public function kilometres(Distance|DistanceDelta $distance, int $maxDecimals = 0): string
    {
        return $this->figure($distance->metres() / 1000, $maxDecimals);
    }

    public function money(Money $money): string
    {
        return $this->amount((float) $money->toDecimal(), $money->currency()->value);
    }

    public function amount(int|float $amount, string $currency): string
    {
        $formatter = new \NumberFormatter($this->convention()->locale, \NumberFormatter::CURRENCY);

        return $this->convention()->group((string) $formatter->formatCurrency($amount, $currency));
    }

    public function percent(int|float $share): string
    {
        $formatter = new \NumberFormatter($this->convention()->locale, \NumberFormatter::PERCENT);

        return $this->convention()->group((string) $formatter->format($share));
    }

    public function convention(): NumberConvention
    {
        return NumberConvention::of(\Locale::getDefault());
    }
}
