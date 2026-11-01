<?php

declare(strict_types=1);

namespace Polaris\Fetching\Service\Enum;

/**
 * What a request for a manual refresh got: a later API maps {@see self::Accepted} to 202 and
 * {@see self::RateLimited} to 429.
 */
enum RefreshRequestStatus: string
{
    case Accepted = 'accepted';
    case RateLimited = 'rate_limited';

    /**
     * The user gave the car back to the bank: nothing is fetched for it any more. A later API maps it to 409.
     */
    case Returned = 'returned';
}
