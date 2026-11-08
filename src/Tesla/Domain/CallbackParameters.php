<?php

declare(strict_types=1);

namespace Polaris\Tesla\Domain;

/**
 * What the Tesla callback carries: the authorization code and the state, or an OAuth error.
 */
final readonly class CallbackParameters
{
    public function __construct(
        #[\SensitiveParameter]
        public ?string $code,
        public ?string $state,
        public ?string $error = null,
    ) {
    }
}
