<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Enum;

/**
 * Why connecting Polaris to a Tesla account was refused. The value is the key of the message in the `tesla`
 * translation domain.
 */
enum ConnectionRejection: string
{
    /**
     * There is no key pair, so no Tesla app row yet.
     */
    case NoApp = 'error.connect.no_app';

    /**
     * The client ID, the client secret or an enabled region is missing.
     */
    case NotConfigured = 'error.connect.not_configured';

    /**
     * The user has not given the consent every way to connect needs (Fleet API Agreement 9.3.7).
     */
    case NoConsent = 'error.connect.no_consent';

    /**
     * The `state` of the callback is not the one kept in the session.
     */
    case BadState = 'error.connect.bad_state';

    /**
     * The pasted text is not a callback URL.
     */
    case BadUrl = 'error.connect.bad_url';

    /**
     * The callback has no authorization code.
     */
    case NoCode = 'error.connect.no_code';

    /**
     * The user, or Tesla, refused the access on the authorization page.
     */
    case Denied = 'error.connect.denied';

    /**
     * Tesla refused the code or the refresh token (expired, used, or from another app).
     */
    case TokenRefused = 'error.connect.token_refused';

    /**
     * Tesla did not answer, or answered with an error that is not a refusal.
     */
    case TeslaFailed = 'error.connect.tesla_failed';
}
