<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service;

/**
 * A connection attempt the user can fix. The message is a translation key of the `tesla` domain.
 */
final class ConnectionRejected extends \RuntimeException
{
    public const string NO_APP = 'error.connect.no_app';
    public const string NOT_CONFIGURED = 'error.connect.not_configured';
    public const string NO_CONSENT = 'error.connect.no_consent';
    public const string BAD_STATE = 'error.connect.bad_state';
    public const string BAD_URL = 'error.connect.bad_url';
    public const string NO_CODE = 'error.connect.no_code';
    public const string DENIED = 'error.connect.denied';
    public const string TOKEN_REFUSED = 'error.connect.token_refused';
    public const string TESLA_FAILED = 'error.connect.tesla_failed';

    public function __construct(
        public readonly string $key,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($key, 0, $previous);
    }
}
