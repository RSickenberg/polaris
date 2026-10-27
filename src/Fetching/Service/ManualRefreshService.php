<?php

declare(strict_types=1);

namespace Polaris\Fetching\Service;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Fetching\Domain\RefreshOutcome;
use Polaris\Fetching\Domain\WakeWindow;
use Polaris\Fetching\Entity\RefreshAttempt;
use Polaris\Fetching\Message\FetchOdometerMessage;
use Polaris\Fetching\Repository\RefreshAttemptRepository;
use Polaris\Fetching\Service\Dto\RefreshRequestResult;
use Polaris\Fetching\Service\Dto\WakeRequestResult;
use Polaris\Fetching\Service\Enum\WakeRequestStatus;
use Polaris\Tesla\Service\Enum\WakeAccess;
use Polaris\Tesla\Service\TeslaAppSettings;
use Polaris\Tesla\Service\TeslaVehicleWaker;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Asks for the odometer of a vehicle now. The hourly limit is decided here, not in the UI, so every caller (the
 * dashboard, a later API) gets the same rule: a request is refused while the hour is spent, but the hour is only
 * spent by the fetch, when it reads an online car ({@see ManualRefreshLimit}); a car found asleep costs nothing
 * against it. An accepted request queues the same
 * {@see FetchOdometerMessage} as a scheduled slot, with no slot, and does not wake the car; the worker settles the
 * {@see RefreshAttempt} it carries. A car found asleep can then be woken once the user confirmed it with
 * {@see self::requestWake()}, which continues that same refresh: it spends the daily wake cap, not a second
 * hourly refresh. The caller checks that the user may manage the vehicle.
 */
final readonly class ManualRefreshService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ManualRefreshLimit $limit,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private RefreshAttemptRepository $attempts,
        private TeslaAppSettings $teslaApps,
        private TeslaVehicleWaker $waker,
        private WakeCap $wakeCap,
    ) {
    }

    /**
     * @throws ORMException
     * @throws ExceptionInterface
     */
    public function request(Vehicle $vehicle): RefreshRequestResult
    {
        $vehicleId = $vehicle->getId()->toRfc4122();

        $retryAfter = $this->limit->retryAfter($vehicle);

        if (null !== $retryAfter) {
            return RefreshRequestResult::rateLimited($retryAfter);
        }

        $attempt = new RefreshAttempt($vehicle, CarbonImmutable::instance($this->clock->now()));
        $this->entityManager->persist($attempt);
        $this->entityManager->flush();

        $this->bus->dispatch(new FetchOdometerMessage($vehicleId, null, 0, $attempt->getId()->toRfc4122()));

        return RefreshRequestResult::accepted($attempt->getId());
    }

    /**
     * Whether a wake could be confirmed now for this refresh, without sending anything: {@see WakeRequestStatus::Accepted}
     * when it could, else the reason it cannot. The dashboard uses it to decide what to offer.
     */
    public function wakeOffer(Vehicle $vehicle, string $attemptId): WakeRequestResult
    {
        $attempt = $this->attempts->findOf($vehicle, $attemptId);

        if (null === $attempt) {
            return WakeRequestResult::refused(WakeRequestStatus::NothingToWake);
        }

        return $this->evaluateWake(
            $vehicle,
            $attempt,
            CarbonImmutable::instance($this->clock->now())
        );
    }

    /**
     * The user confirmed waking the car a refresh found asleep or offline. The cap is checked here for a quick answer and again
     * by the fetch, which counts a wake when wake_up is actually sent.
     *
     * @throws ORMException
     * @throws ExceptionInterface
     */
    public function requestWake(Vehicle $vehicle, string $attemptId): WakeRequestResult
    {
        $attempt = $this->attempts->findOf($vehicle, $attemptId);

        if (null === $attempt) {
            return WakeRequestResult::refused(WakeRequestStatus::NothingToWake);
        }

        $now = CarbonImmutable::instance($this->clock->now());
        $result = $this->evaluateWake($vehicle, $attempt, $now);

        if (!$result->isAccepted()) {
            return $result;
        }

        $attempt->requeue($now);
        $this->entityManager->flush();

        $this->bus->dispatch(new FetchOdometerMessage(
            $vehicle->getId()->toRfc4122(),
            null,
            0,
            $attempt->getId()->toRfc4122(),
            true
        ));

        return $result;
    }

    private function evaluateWake(Vehicle $vehicle, RefreshAttempt $attempt, CarbonImmutable $now): WakeRequestResult
    {
        $expired = $attempt->getUpdatedAt()->addSeconds(WakeWindow::CONFIRM_SECONDS) < $now;

        if ($expired || !\in_array($attempt->getOutcome(), [RefreshOutcome::Asleep, RefreshOutcome::Offline], true)) {
            return WakeRequestResult::refused(WakeRequestStatus::NothingToWake);
        }

        if ($this->attempts->hasPendingWake($vehicle, WakeWindow::pendingSince($now))) {
            return WakeRequestResult::refused(WakeRequestStatus::NothingToWake);
        }

        $app = $this->teslaApps->find($vehicle->getOwner());

        if (null === $app) {
            return WakeRequestResult::refused(WakeRequestStatus::NotConnected);
        }

        $refusal = match ($this->waker->access($app)) {
            WakeAccess::NotConnected => WakeRequestStatus::NotConnected,
            WakeAccess::ScopeMissing => WakeRequestStatus::ScopeMissing,
            WakeAccess::Allowed => null,
        };

        if (null !== $refusal) {
            return WakeRequestResult::refused($refusal);
        }

        // The read that follows the wake spends the hour: a wake that could not be read would be paid for nothing.
        $retryAfter = $this->limit->retryAfter($vehicle);

        if (null !== $retryAfter) {
            return WakeRequestResult::rateLimited($retryAfter);
        }

        $cap = $this->wakeCap->status($vehicle, $app, $now);

        return $cap->isReached() ? WakeRequestResult::capReached($cap->availableAt) : WakeRequestResult::accepted();
    }
}
