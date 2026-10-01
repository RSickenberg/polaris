<?php

declare(strict_types=1);

namespace Polaris\Shared\Domain;

/**
 * Picks the locale of a request among the enabled ones: the user's preference first, then
 * the best match of the Accept-Language header (by quality value, then by order), then the
 * default. Regional variants match their language: "fr-CH" is served as "fr".
 */
final readonly class LocaleNegotiator
{
    /**
     * @param list<string> $supported the enabled locales, for example ['en', 'fr']
     */
    public function __construct(
        private array $supported,
        private string $default,
    ) {
    }

    public function negotiate(?string $preferred, ?string $acceptLanguage): string
    {
        if (null !== $preferred && null !== $locale = $this->match($preferred)) {
            return $locale;
        }

        foreach ($this->acceptedTags($acceptLanguage) as $tag) {
            if (null !== $locale = $this->match($tag)) {
                return $locale;
            }
        }

        return $this->default;
    }

    /**
     * The language tags of the header, best first. Tags with q=0 (not acceptable) and the
     * wildcard are dropped; entries with an unreadable quality value are ignored.
     *
     * @return list<string>
     */
    private function acceptedTags(?string $header): array
    {
        if (null === $header) {
            return [];
        }

        $accepted = [];
        foreach (explode(',', $header) as $entry) {
            $parts = array_map(trim(...), explode(';', $entry));
            $tag = array_shift($parts);
            if ('' === $tag || '*' === $tag) {
                continue;
            }

            $quality = 1.0;
            foreach ($parts as $parameter) {
                if (1 === preg_match('/^q=(\d(?:\.\d{0,3})?)$/i', $parameter, $matches)) {
                    $quality = (float) $matches[1];
                } elseif (str_starts_with(strtolower($parameter), 'q=')) {
                    continue 2;
                }
            }

            if ($quality > 0.0) {
                $accepted[] = ['tag' => $tag, 'quality' => min($quality, 1.0)];
            }
        }

        // usort is stable: tags with the same quality keep the order of the header.
        usort($accepted, static fn (array $a, array $b): int => $b['quality'] <=> $a['quality']);

        return array_column($accepted, 'tag');
    }

    /**
     * The enabled locale a tag stands for: the tag itself, or its language ("fr-CH" is "fr").
     */
    private function match(string $tag): ?string
    {
        $normalized = $tag
                |> trim(...)
                |> (static fn ($x) => str_replace('_', '-', $x))
                |> strtolower(...);
        $language = explode('-', $normalized)[0];

        foreach ([$normalized, $language] as $candidate) {
            foreach ($this->supported as $locale) {
                if (strtolower(str_replace('_', '-', $locale)) === $candidate) {
                    return $locale;
                }
            }
        }

        return null;
    }
}
