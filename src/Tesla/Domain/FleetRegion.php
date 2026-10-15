<?php

declare(strict_types=1);

namespace Polaris\Tesla\Domain;

/**
 * A Tesla Fleet API region. Each region has its own base URL, and an app must be registered once
 * in every region it operates in.
 *
 * China is a separate Tesla account and a separate developer app on developer.tesla.cn, with its
 * own credentials. Polaris does not support it yet: the case exists so that it can be named and
 * refused with a clear message, and its token host is deliberately unknown.
 */
enum FleetRegion: string
{
    private const string TOKEN_URL = 'https://fleet-auth.prd.vn.cloud.tesla.com/oauth2/v3/token';

    case NorthAmericaAsiaPacific = 'na';
    case EuropeMiddleEastAfrica = 'eu';
    case China = 'cn';

    public function baseUrl(): string
    {
        return match ($this) {
            self::NorthAmericaAsiaPacific => 'https://fleet-api.prd.na.vn.cloud.tesla.com',
            self::EuropeMiddleEastAfrica => 'https://fleet-api.prd.eu.vn.cloud.tesla.com',
            self::China => 'https://fleet-api.prd.cn.vn.cloud.tesla.cn',
        };
    }

    public function isSupported(): bool
    {
        return self::China !== $this;
    }

    /**
     * The OAuth token endpoint: the global host serves the NA and EMEA regions.
     *
     * @throws \LogicException for China, whose auth host has not been verified
     */
    public function tokenUrl(): string
    {
        if (!$this->isSupported()) {
            throw new \LogicException('The token host of the China region is not known: the region is not supported.');
        }

        return self::TOKEN_URL;
    }

    /**
     * @return list<self>
     */
    public static function supported(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $region): bool => $region->isSupported(),
        ));
    }
}
