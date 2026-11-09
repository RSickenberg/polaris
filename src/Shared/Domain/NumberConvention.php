<?php

declare(strict_types=1);

namespace Polaris\Shared\Domain;

/**
 * How figures are written for a language: the ICU locale that formats them and the character that groups the
 * thousands. French follows Swiss usage (maintainer's choice): fr_CH formats (a point in amounts, "45%"), and an
 * apostrophe groups the thousands (12’345) where ICU writes a narrow no-break space. Other languages keep ICU's
 * defaults.
 */
final readonly class NumberConvention
{
    /**
     * @var array<string, array{string, string}> the ICU locale and the grouping character, by language
     */
    private const array CONVENTIONS = [
        'fr' => ['fr_CH', '’'],
    ];

    private function __construct(
        /** The ICU locale that formats the figures, for example fr_CH. */
        public string $locale,
        /** The character between groups of thousands, null to keep the one of the locale. */
        public ?string $groupingSeparator,
    ) {
    }

    /**
     * @param string $locale the locale of the request or the user, for example fr or en_GB
     */
    public static function of(string $locale): self
    {
        $language = strtolower(substr($locale, 0, 2));
        [$formattingLocale, $grouping] = self::CONVENTIONS[$language] ?? [$locale, null];

        return new self($formattingLocale, $grouping);
    }

    /**
     * The locale as a BCP 47 tag, for the browser (Intl): fr-CH.
     */
    public function languageTag(): string
    {
        return str_replace('_', '-', $this->locale);
    }

    /**
     * Puts the grouping character in a figure formatted by ICU for {@see self::$locale}: a no-break or narrow
     * no-break space followed by three digits becomes it. Text around the figure, and regular spaces, are left alone.
     */
    public function group(string $formatted): string
    {
        if (null === $this->groupingSeparator) {
            return $formatted;
        }

        return preg_replace('/(?<=\d)[\x{00A0}\x{202F}](?=\d{3})/u', $this->groupingSeparator, $formatted)
            ?? $formatted;
    }
}
