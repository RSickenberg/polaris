<?php

declare(strict_types=1);

namespace Polaris\Shared\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Formats an instant for the active locale in the user's time zone, to the minute and with the
 * zone offset, which tells apart the two 02:30 of a daylight saving change (ADR 0003). The
 * date is stored and computed in UTC; the conversion happens only here.
 */
final class LocalizedDatetimeExtension extends AbstractExtension
{
    private const string SKELETON = 'yMMMdjmz';

    public function getFilters(): array
    {
        return [new TwigFilter('localized_datetime', $this->format(...))];
    }

    /**
     * @param string $timeZone an IANA time zone name
     */
    public function format(\DateTimeInterface $date, string $timeZone, ?string $locale = null): string
    {
        $locale ??= \Locale::getDefault();
        $pattern = new \IntlDatePatternGenerator($locale)->getBestPattern(self::SKELETON);
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $timeZone, \IntlDateFormatter::GREGORIAN, false === $pattern ? null : $pattern);

        return (string) $formatter->format($date->getTimestamp());
    }
}
