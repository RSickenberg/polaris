<?php

declare(strict_types=1);

namespace Polaris\Fetching\Service;

use Carbon\CarbonImmutable;
use Polaris\Extension\ManualRefreshPolicy;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The hourly limit of the manual refresh of one vehicle, on the `manual_refresh` limiter. It is spent when the
 * car is asked for its odometer (the billed live read), not when the button is clicked: a car found asleep is
 * only checked, so it costs nothing against the limit. A click and a wake only look at it ({@see self::retryAfter()}).
 */
final readonly class ManualRefreshLimit
{
    public function __construct(
        private ManualRefreshPolicy $policy,
        private RateLimiterFactoryInterface $manualRefreshLimiter,
    ) {
    }

    /**
     * When the vehicle can be read again, null when it can be read now. Nothing is consumed.
     */
    public function retryAfter(Vehicle $vehicle): ?CarbonImmutable
    {
        if (!$this->policy->isLimited($vehicle)) {
            return null;
        }

        $limit = $this->manualRefreshLimiter->create($vehicle->getId()->toRfc4122())->reserve(0)->getRateLimit();

        return $limit->getRemainingTokens() > 0 ? null : CarbonImmutable::instance($limit->getRetryAfter())->utc();
    }

    /**
     * Spends the hour of the vehicle.
     *
     * @return CarbonImmutable|null null when the read may go on, else when the vehicle can be read again
     */
    public function consume(Vehicle $vehicle): ?CarbonImmutable
    {
        if (!$this->policy->isLimited($vehicle)) {
            return null;
        }

        $limit = $this->manualRefreshLimiter->create($vehicle->getId()->toRfc4122())->consume();

        return $limit->isAccepted() ? null : CarbonImmutable::instance($limit->getRetryAfter())->utc();
    }
}
