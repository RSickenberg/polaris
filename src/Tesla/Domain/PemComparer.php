<?php

declare(strict_types=1);

namespace Polaris\Tesla\Domain;

/**
 * Compares two PEM texts while ignoring line endings, trailing newlines and indentation, so that a
 * key served by a static site matches the key stored by Polaris.
 */
final class PemComparer
{
    public function same(string $first, string $second): bool
    {
        $normalizedFirst = $this->normalize($first);

        return '' !== $normalizedFirst
            && hash_equals($normalizedFirst, $this->normalize($second));
    }

    private function normalize(string $pem): string
    {
        return (string) preg_replace('/\s+/', '', $pem);
    }
}
