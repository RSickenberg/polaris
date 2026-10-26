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
}
