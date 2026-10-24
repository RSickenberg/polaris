<?php

declare(strict_types=1);

namespace Polaris\Tesla\Domain;

/**
 * How the user connected the Tesla account (ADR 0004): the authorization redirect, a pasted callback URL
 * (the browser could not reach Polaris), or a refresh token obtained outside Polaris.
 */
enum ConnectionMethod: string
{
    case OAuth = 'oauth';
    case PastedCallback = 'pasted_callback';
    case PastedRefreshToken = 'pasted_refresh_token';
}
