<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service;

use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Service\Exception\FleetApiError;
use Polaris\Tesla\Service\Exception\TeslaNotConnected;

/**
 * Gives the fetching code a valid Tesla access token for an app, refreshing it when it is about to
 * expire. The only way to read an access token: callers never touch the refresh token.
 */
interface TokenProvider
{
    /**
     * @param bool $force refresh even when the token is not due (a manual refresh, to check the connection)
     *
     * @throws TeslaNotConnected when there is no connection, or Tesla refused the refresh token
     * @throws FleetApiError     when Tesla could not be reached: the stored tokens are untouched
     */
    public function accessToken(TeslaApp $app, bool $force = false): string;
}
