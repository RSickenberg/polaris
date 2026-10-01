<?php

declare(strict_types=1);

namespace Polaris\Tests\Support;

use Symfony\Component\Translation\Loader\XliffFileLoader;

/**
 * Compares the XLIFF files of the source locale with those of another locale, key by key.
 * `debug:translation --only-missing` only reports keys it can extract from the code, so a
 * key built at run time (an enum value, for example) could lack its translation unnoticed.
 */
final class TranslationFilesChecker
{
    public function __construct(
        private readonly string $directory,
        private readonly string $sourceLocale = 'en',
    ) {
    }

    /**
     * @return list<string> what is wrong with the files of $locale; empty when they are complete
     */
    public function check(string $locale): array
    {
        $loader = new XliffFileLoader();
        $problems = [];

        foreach (glob(\sprintf('%s/*.%s.xlf', $this->directory, $this->sourceLocale)) ?: [] as $sourceFile) {
            $name = basename($sourceFile, \sprintf('.%s.xlf', $this->sourceLocale));
            $file = \sprintf('%s/%s.%s.xlf', $this->directory, $name, $locale);

            if (!is_file($file)) {
                $problems[] = \sprintf('%s: the file is missing', basename($file));
                continue;
            }

            /** @var array<string, string> $source */
            $source = $loader->load($sourceFile, $this->sourceLocale)->all('messages');
            /** @var array<string, string> $translated */
            $translated = $loader->load($file, $locale)->all('messages');

            foreach (array_diff_key($source, $translated) as $key => $unused) {
                $problems[] = \sprintf('%s: missing key "%s"', basename($file), $key);
            }
            foreach (array_diff_key($translated, $source) as $key => $unused) {
                $problems[] = \sprintf('%s: key "%s" does not exist in %s', basename($file), $key, $this->sourceLocale);
            }
            foreach (array_intersect_key($translated, $source) as $key => $text) {
                if ('' === trim($text)) {
                    $problems[] = \sprintf('%s: empty translation for "%s"', basename($file), $key);
                } elseif (self::placeholders($text) !== self::placeholders($source[$key])) {
                    $problems[] = \sprintf('%s: "%s" does not use the same placeholders as %s', basename($file), $key, $this->sourceLocale);
                }
            }
        }

        foreach (glob(\sprintf('%s/*.%s.xlf', $this->directory, $locale)) ?: [] as $file) {
            if (!is_file(\sprintf('%s/%s.%s.xlf', $this->directory, basename($file, \sprintf('.%s.xlf', $locale)), $this->sourceLocale))) {
                $problems[] = \sprintf('%s: there is no %s counterpart', basename($file), $this->sourceLocale);
            }
        }

        return $problems;
    }

    /**
     * @return list<string> the placeholder names of a message, for %name%, {{ name }} and ICU {name}
     */
    private static function placeholders(string $message): array
    {
        preg_match_all('/%(\w+)%|\{\{\s*(\w+)\s*\}\}|\{(\w+)\s*[,}]/', $message, $matches);
        $names = array_unique(array_filter([...$matches[1], ...$matches[2], ...$matches[3]]));
        sort($names);

        return $names;
    }
}
