<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Dto;

/**
 * An authorization request to start: where to send the browser, and the `state` to keep in the session
 * until Tesla sends the user back.
 */
final readonly class AuthorizationRequest
{
    public function __construct(
        public string $url,
        #[\SensitiveParameter]
        public string $state,
    ) {
    }
}
