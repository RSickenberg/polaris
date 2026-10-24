<?php

declare(strict_types=1);

namespace Polaris\Tesla\Domain;

use Random\RandomException;

/**
 * The random `state` of an authorization request. It is kept in the session and compared when the
 * user comes back, so a callback that Polaris did not start is refused.
 */
final class OAuthState
{
    private const int BYTES = 32;

    private function __construct()
    {
    }

    /**
     * @throws RandomException
     */
    public static function generate(): string
    {
        return bin2hex(random_bytes(self::BYTES));
    }

    public static function matches(?string $expected, ?string $received): bool
    {
        return null !== $expected && '' !== $expected && null !== $received && hash_equals($expected, $received);
    }
}
