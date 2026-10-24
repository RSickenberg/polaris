<?php

declare(strict_types=1);

namespace Polaris\Tesla\Domain;

/**
 * The tokens of a code exchange or a refresh. `expiresIn` is in seconds; `scopes` is the space-delimited
 * list Tesla granted (the requested one when Tesla does not repeat it).
 */
final readonly class TokenSet
{
    public function __construct(
        #[\SensitiveParameter]
        public string $accessToken,
        #[\SensitiveParameter]
        public string $refreshToken,
        public int $expiresIn,
        public string $scopes,
    ) {
    }
}
