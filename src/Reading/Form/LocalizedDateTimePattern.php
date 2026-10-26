<?php

declare(strict_types=1);

namespace Polaris\Reading\Form;

/**
 * The date and time pattern a person types in a locale: the short ICU pattern of the locale made unambiguous
 * (four-digit year, two-digit day, month and hour), for example `dd/MM/yyyy HH:mm` in French and
 * `MM/dd/yyyy, hh:mm a` in English.
 */
final class LocalizedDateTimePattern
{
    private const string EXAMPLE = '2026-12-31 23:59:00';

    public static function for(string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::SHORT, \IntlDateFormatter::SHORT, 'UTC');
        // ICU puts a no-break space before the AM/PM marker: a person types a plain one.
        $pattern = str_replace(["\u{202F}", "\u{00A0}"], ' ', (string) $formatter->getPattern());

        // A letter alone or doubled becomes the fixed width: y and yy, M, d, h and H (not MM, MMM, ...).
        return (string) preg_replace_callback(
            '/(?<!\')(y+|M+|d+|h+|H+)(?!\')/',
            static fn (array $match): string => match ($match[1][0]) {
                'y' => 'yyyy',
                default => str_repeat($match[1][0], max(2, \strlen($match[1]))),
            },
            $pattern,
        );
    }

    /**
     * The same fixed example for every locale, to show the order and the separators in a placeholder.
     */
    public static function example(string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::SHORT, \IntlDateFormatter::SHORT, 'UTC', null, self::for($locale));

        return (string) $formatter->format(new \DateTimeImmutable(self::EXAMPLE, new \DateTimeZone('UTC')));
    }
}
