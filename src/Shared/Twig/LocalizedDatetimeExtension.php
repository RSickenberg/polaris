<?php

declare(strict_types=1);

namespace Polaris\Shared\Twig;

use Carbon\CarbonImmutable;
use Psr\Clock\ClockInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Formats an instant for the active locale in the user's time zone, to the minute and with the
 * zone offset, which tells apart the two 02:30 of a daylight saving change (ADR 0003). The
 * date is stored and computed in UTC; the conversion happens only here.
 */
final class LocalizedDatetimeExtension extends AbstractExtension
{
    private const string SKELETON = 'yMMMdjm';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('localized_datetime', $this->format(...)),
            new TwigFilter('humanized_datetime', $this->humanize(...)),
        ];
    }

    /**
     * @param string $timeZone an IANA time zone name
     * @param bool   $withZone false leaves the zone out, where the page already states it
     */
    public function format(\DateTimeInterface $date, string $timeZone, ?string $locale = null, bool $withZone = true): string
    {
        $locale ??= \Locale::getDefault();
        $pattern = new \IntlDatePatternGenerator($locale)->getBestPattern(self::SKELETON . ($withZone ? 'z' : ''));
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $timeZone, \IntlDateFormatter::GREGORIAN, false === $pattern ? null : $pattern);

        return (string) $formatter->format($date->getTimestamp());
    }

    /**
     * Today and the days around it in words ("Today at 11:00 AM", "Yesterday at ..."), the last
     * week by weekday, anything older as {@see format()} without the zone. Days are those of
     * the user's time zone, and "now" comes from the clock.
     *
     * @param string $timeZone an IANA time zone name
     */
    public function humanize(\DateTimeInterface $date, string $timeZone, ?string $locale = null): string
    {
        $locale ??= \Locale::getDefault();
        $now = CarbonImmutable::instance($this->clock->now())->setTimezone($timeZone);

        $at = CarbonImmutable::instance($date)->setTimezone($timeZone)->locale(\Locale::getPrimaryLanguage($locale) ?? 'en');
        \assert($at instanceof CarbonImmutable);

        // Carbon reads what the callback returns as a format: the brackets keep the text as it is.
        return (string) $at->calendar($now, ['sameElse' => fn (): string => '[' . $this->format($date, $timeZone, $locale, false) . ']']);
    }
}
