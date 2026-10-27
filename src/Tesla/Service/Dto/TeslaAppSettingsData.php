<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Dto;

use Polaris\Tesla\Domain\FleetRegion;

/**
 * What the user enters about the Tesla app. A null client secret keeps the stored one.
 */
final readonly class TeslaAppSettingsData
{
    /**
     * @param list<FleetRegion> $regions
     * @param int|null          $dailyWakeCap    null keeps the stored cap
     * @param bool|null         $keepVehicleData null keeps the stored choice
     */
    public function __construct(
        public string $clientId,

        #[\SensitiveParameter]
        public ?string $clientSecret,

        public string $domain,

        public array $regions,

        public ?int $dailyWakeCap = null,

        public ?bool $keepVehicleData = null,
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
            'dailyWakeCap' => $this->dailyWakeCap,
            'keepVehicleData' => $this->keepVehicleData,
        ];
    }
}
