<?php

declare(strict_types=1);

namespace Polaris\Tesla\Domain;

enum ConnectionStatus: string
{
    case Connected = 'connected';

    /**
     * Tesla refused the refresh token (expired, replaced or the password was reset): the user connects again.
     */
    case Expired = 'expired';
}
