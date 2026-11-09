<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Service\Exception;

/**
 * The user cannot have more vehicles. The message is English, for the console and the logs; the pages say it with the
 * `import.limit.*` keys of the `vehicle` translation domain and {@see $limit}.
 */
final class VehicleLimitReached extends \RuntimeException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct(
            \sprintf(
                'The limit of %d %s is reached: a self-hosted Polaris tracks a single vehicle.',
                $limit,
                1 === $limit ? 'vehicle' : 'vehicles',
            ),
        );
    }
}
