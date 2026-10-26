<?php

declare(strict_types=1);

namespace Polaris\Extension;

use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The self-hosted default: POLARIS_MANUAL_REFRESH_LIMIT is 1 (limited, the default) or 0 (no limit).
 */
#[AsAlias(ManualRefreshPolicy::class)]
final readonly class ConfiguredManualRefreshPolicy implements ManualRefreshPolicy
{
    public function __construct(
        #[Autowire(env: 'bool:POLARIS_MANUAL_REFRESH_LIMIT')]
        private bool $limited,
    ) {
    }

    public function isLimited(Vehicle $vehicle): bool
    {
        return $this->limited;
    }
}
