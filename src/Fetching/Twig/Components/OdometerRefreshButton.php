<?php

declare(strict_types=1);

namespace Polaris\Fetching\Twig\Components;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Account\Entity\User;
use Polaris\Fetching\Domain\RefreshOutcome;
use Polaris\Fetching\Entity\RefreshAttempt;
use Polaris\Fetching\Repository\RefreshAttemptRepository;
use Polaris\Fetching\Service\Dto\WakeRequestResult;
use Polaris\Fetching\Service\Enum\WakeRequestStatus;
use Polaris\Fetching\Service\ManualRefreshService;
use Polaris\Vehicle\Entity\Vehicle;
use Polaris\Vehicle\Service\Enum\VehiclePermission;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Uid\Ulid;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Refreshes the odometer of one vehicle by hand. The click only calls {@see ManualRefreshService}, which decides
 * the hourly limit and queues the fetch; the component then polls the {@see RefreshAttempt} until the worker
 * settles it. Every request to the component (render and action) checks that the user manages the vehicle:
 * another user's vehicle is a 404, as on the dashboard.
 */
#[AsLiveComponent(template: 'components/Fetching/OdometerRefreshButton.html.twig')]
final class OdometerRefreshButton
{
    use DefaultActionTrait;

    /**
     * How long the component waits for the worker before it stops polling and says the result will come later.
     */
    private const int POLL_SECONDS = 120;

    /**
     * The ULID (RFC 4122 form) of the vehicle. Not writable by the browser: the props are signed.
     */
    #[LiveProp]
    public string $vehicleId = '';

    /**
     * The attempt this component started, null before the first click.
     */
    #[LiveProp]
    public ?string $attemptId = null;

    /**
     * The UTC timestamp from which a limited request can be repeated, null when the last click was accepted.
     */
    #[LiveProp]
    public ?int $limitedUntil = null;

    /**
     * The attempt whose wake offer the user turned down ("Use last reading"), so the dialog does not come back.
     */
    #[LiveProp]
    public ?string $declinedAttemptId = null;

    /**
     * What the last confirmed wake got when it was refused, a {@see WakeRequestStatus} value; null otherwise.
     */
    #[LiveProp]
    public ?string $wakeRefusal = null;

    /**
     * The UTC timestamp from which another wake is allowed, set with a refused wake because of the cap.
     */
    #[LiveProp]
    public ?int $wakeAvailableAt = null;

    /**
     * How many times the button was clicked in this page: it makes the toast of each click a new element.
     */
    #[LiveProp]
    public int $clicks = 0;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,

        private readonly RefreshAttemptRepository $attempts,

        private readonly ManualRefreshService $refreshes,

        private readonly Security $security,

        private readonly ClockInterface $clock,

        #[Autowire(param: 'polaris.tesla.wake_cost')]
        private readonly float $wakeCost,

        #[Autowire(param: 'polaris.tesla.wake_currency')]
        private readonly string $wakeCurrency,
    ) {
    }

    /**
     * @throws ORMException
     * @throws ExceptionInterface
     */
    #[LiveAction]
    public function refresh(): void
    {
        $result = $this->refreshes->request($this->vehicle());
        ++$this->clicks;

        $this->attemptId = $result->attemptId?->toRfc4122();
        $this->limitedUntil = $result->retryAfter?->getTimestamp();
        $this->declinedAttemptId = null;
        $this->wakeRefusal = null;
        $this->wakeAvailableAt = null;
    }

    /**
     * The user confirmed waking the car in the dialog.
     *
     * @throws ORMException
     * @throws ExceptionInterface
     */
    #[LiveAction]
    public function wake(): void
    {
        ++$this->clicks;

        if (null === $this->attemptId) {
            return;
        }

        $result = $this->refreshes->requestWake($this->vehicle(), $this->attemptId);

        $this->wakeRefusal = $result->isAccepted() ? null : $result->status->value;
        $this->wakeAvailableAt = $result->availableAt?->getTimestamp();
    }

    /**
     * "Use last reading": the dialog is closed and stays closed for this refresh.
     */
    #[LiveAction]
    public function declineWake(): void
    {
        $this->declinedAttemptId = $this->attemptId;
    }

    /**
     * What the dashboard offers for a car the last refresh found asleep, null when it offers nothing (the car
     * is not asleep, the offer was declined or a wake is running).
     */
    public function wakeOffer(): ?WakeRequestResult
    {
        if (null === $this->attemptId || RefreshOutcome::Asleep !== $this->outcome() || $this->declinedAttemptId === $this->attemptId) {
            return null;
        }

        return $this->refreshes->wakeOffer($this->vehicle(), $this->attemptId);
    }

    public function wakeAvailableAt(): ?CarbonImmutable
    {
        return null === $this->wakeAvailableAt ? null : CarbonImmutable::createFromTimestampUTC($this->wakeAvailableAt);
    }

    public function wakeCost(): float
    {
        return $this->wakeCost;
    }

    public function wakeCurrency(): string
    {
        return $this->wakeCurrency;
    }

    public function retryAt(): ?CarbonImmutable
    {
        return null === $this->limitedUntil ? null : CarbonImmutable::createFromTimestampUTC($this->limitedUntil);
    }

    public function outcome(): ?RefreshOutcome
    {
        return $this->attempt()?->getOutcome();
    }

    /**
     * Whether the fetch is queued or waiting for a woken car, and recent enough to wait for.
     */
    public function isPolling(): bool
    {
        $attempt = $this->attempt();

        return null !== $attempt
            && !$attempt->getOutcome()->isSettled()
            && $attempt->getUpdatedAt()->addSeconds(self::POLL_SECONDS) > CarbonImmutable::instance($this->clock->now());
    }

    public function timeZone(): string
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getTimeZone() : 'UTC';
    }

    private function attempt(): ?RefreshAttempt
    {
        return null === $this->attemptId ? null : $this->attempts->findOf($this->vehicle(), $this->attemptId);
    }

    private function vehicle(): Vehicle
    {
        try {
            $vehicle = $this->entityManager->find(Vehicle::class, Ulid::fromString($this->vehicleId));
        } catch (\InvalidArgumentException) {
            $vehicle = null;
        }

        if (!$vehicle instanceof Vehicle || !$this->security->isGranted(VehiclePermission::Manage->value, $vehicle)) {
            throw new NotFoundHttpException();
        }

        return $vehicle;
    }
}
