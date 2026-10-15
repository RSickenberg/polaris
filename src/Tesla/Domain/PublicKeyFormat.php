<?php

declare(strict_types=1);

namespace Polaris\Tesla\Domain;

/**
 * Compares the stored public key (PEM) with what the Fleet API returns for a registered domain.
 * The shape of that response is not documented, so both a PEM text and the hex of the uncompressed
 * EC point (04 || X || Y) are accepted.
 */
final readonly class PublicKeyFormat
{
    public function __construct(private PemComparer $comparer)
    {
    }

    public function sameKey(string $pem, string $returned): bool
    {
        $returned = trim($returned);

        if (str_contains($returned, 'BEGIN PUBLIC KEY')) {
            return $this->comparer->same($pem, $returned);
        }

        $hex = $this->uncompressedPointHex($pem);

        return null !== $hex
            && 1 === preg_match('/^[0-9a-fA-F]+$/', $returned)
            && hash_equals($hex, strtolower($returned));
    }

    public function uncompressedPointHex(string $pem): ?string
    {
        $key = openssl_pkey_get_public($pem);
        $details = false === $key ? false : openssl_pkey_get_details($key);
        $ec = \is_array($details) ? ($details['ec'] ?? null) : null;
        $x = \is_array($ec) ? ($ec['x'] ?? null) : null;
        $y = \is_array($ec) ? ($ec['y'] ?? null) : null;

        if (!\is_string($x) || !\is_string($y)) {
            return null;
        }

        return '04' . bin2hex(str_pad($x, 32, "\0", \STR_PAD_LEFT)) . bin2hex(str_pad($y, 32, "\0", \STR_PAD_LEFT));
    }
}
