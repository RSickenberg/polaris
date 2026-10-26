<?php

declare(strict_types=1);

namespace Polaris\Fetching\Service;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Extension\ManualRefreshPolicy;
use Polaris\Fetching\Entity\RefreshAttempt;
use Polaris\Fetching\Message\FetchOdometerMessage;
use Polaris\Fetching\Service\Dto\RefreshRequestResult;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Asks for the odometer of a vehicle now. The hourly limit is decided and consumed here, not in the UI, so
 * every caller (the dashboard, a later API) gets the same rule. An accepted request queues the same
 * {@see FetchOdometerMessage} as a scheduled slot, with no slot, and never wakes the car; the worker settles the
 * {@see RefreshAttempt} it carries. The caller checks that the user may manage the vehicle.
 */
final readonly class ManualRefreshService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ManualRefreshPolicy $policy,
        private RateLimiterFactoryInterface $manualRefreshLimiter,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ORMException
     * @throws ExceptionInterface
     */
    public function request(Vehicle $vehicle): RefreshRequestResult
    {
        $vehicleId = $vehicle->getId()->toRfc4122();

        if ($this->policy->isLimited($vehicle)) {
            $limit = $this->manualRefreshLimiter->create($vehicleId)->consume();

            if (!$limit->isAccepted()) {
                return RefreshRequestResult::rateLimited(CarbonImmutable::instance($limit->getRetryAfter())->utc());
            }
        }

        $attempt = new RefreshAttempt($vehicle, CarbonImmutable::instance($this->clock->now()));
        $this->entityManager->persist($attempt);
        $this->entityManager->flush();

        $this->bus->dispatch(new FetchOdometerMessage($vehicleId, null, 0, $attempt->getId()->toRfc4122()));

        return RefreshRequestResult::accepted($attempt->getId());
    }
}
