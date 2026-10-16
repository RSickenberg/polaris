<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Domain;

/**
 * The three curves of the mileage chart, as distances driven since the lease start.
 */
final readonly class MileageSeries
{
    /**
     * @param non-empty-list<MileagePoint> $actual     the lease start, then one point per reading, oldest first
     * @param non-empty-list<MileagePoint> $allowed    from the lease start to the lease end, linear
     * @param list<MileagePoint>           $projection from the latest reading to the lease end, empty when there is none
     */
    public function __construct(
        public array $actual,
        public array $allowed,
        public array $projection,
    ) {
    }

    public function hasProjection(): bool
    {
        return [] !== $this->projection;
    }
}
