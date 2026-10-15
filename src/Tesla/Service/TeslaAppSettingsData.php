<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service;

use Polaris\Tesla\Domain\FleetRegion;

/**
 * What the user enters about the Tesla app. A null client secret keeps the stored one.
 */
final readonly class TeslaAppSettingsData
{
    /**
     * @param list<FleetRegion> $regions
     */
    public function __construct(
        public string $clientId,
        #[\SensitiveParameter]
        public ?string $clientSecret,
        public string $domain,
        public array $regions,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'clientId' => $this->clientId,
            'domain' => $this->domain,
            'regions' => $this->regions,
        ];
    }
}
