<?php

declare(strict_types=1);

namespace Polaris\Tesla\Domain;

/**
 * The Fleet API region of a Tesla user, from GET /api/1/users/region.
 */
final readonly class UserRegion
{
    public function __construct(
        public FleetRegion $region,
        public string $baseUrl,
    ) {
    }
}
