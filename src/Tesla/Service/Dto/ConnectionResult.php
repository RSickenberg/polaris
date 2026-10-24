<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Dto;

use Polaris\Tesla\Entity\OAuthToken;

/**
 * A connection that was stored. `regionDiffers` is true when the region of the Tesla account is not the
 * one the tokens were requested for (the audience): Tesla does not document what then happens, so the
 * user is told to enable the account's region in the app settings.
 */
final readonly class ConnectionResult
{
    public function __construct(
        public OAuthToken $token,
        public bool $regionDiffers,
    ) {
    }
}
