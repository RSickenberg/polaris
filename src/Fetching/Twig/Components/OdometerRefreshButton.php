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
use Polaris\Fetching\Service\Enum\RefreshRequestStatus;
use Polaris\Fetching\Service\Enum\WakeRequestStatus;
use Polaris\Fetching\Service\ManualRefreshService;
use Polaris\Shared\Service\Enum\ToastVariant;
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
     * What the last confirmed wake got when it was refused; null otherwise.
     */
    #[LiveProp]
    public ?WakeRequestStatus $wakeRefusal = null;

    /**
     * The UTC timestamp from which another wake is allowed, set with a refused wake because of the cap.
     */
    #[LiveProp]
    public ?int $wakeAvailableAt = null;

    /**
     * Whether the last click found the lease returned (a return made in another tab since the page was drawn).
     */
    #[LiveProp]
    public bool $returned = false;

    /**
     * How many times the button was clicked in this page: it makes the toast of each click a new element.
     */
    #[LiveProp]
    public int $clicks = 0;

    /**
     * Loaded once per request: the template and {@see self::toast()} ask for them several times.
     */
    private ?Vehicle $loadedVehicle = null;

    private ?RefreshAttempt $loadedAttempt = null;

    private ?WakeRequestResult $offer = null;

    private bool $offerLoaded = false;

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
        $this->returned = RefreshRequestStatus::Returned === $result->status;
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

        $this->wakeRefusal = $result->isAccepted() ? null : $result->status;
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
        if (!$this->offerLoaded) {
            $this->offer = $this->loadWakeOffer();
            $this->offerLoaded = true;
        }

        return $this->offer;
    }

    /**
     * Whether the dialog that asks to wake the car is open.
     */
    public function isWakeDialogOpen(): bool
    {
        return true === $this->wakeOffer()?->isAccepted();
    }

    /**
     * The translation key prefix of the dialog: an offline car gets a dialog that promises nothing.
     */
    public function wakeDialogKey(): string
    {
        return RefreshOutcome::Offline === $this->outcome() ? 'wake.dialog_offline' : 'wake.dialog';
    }

    /**
     * Whether the connection lacks the scope to wake: the component then links to the reconnection.
     */
    public function needsReconnectToWake(): bool
    {
        return WakeRequestStatus::ScopeMissing === $this->wakeOffer()?->status;
    }

    /**
     * What to announce after the last click, null when there is nothing new to say.
     */
    public function toast(): ?RefreshToast
    {
        $offer = $this->wakeOffer();
        $offerStatus = $offer?->status;
        $offerRefused = WakeRequestStatus::CapReached === $offerStatus || WakeRequestStatus::RateLimited === $offerStatus;
        $wakeStatus = $this->wakeRefusal ?? ($offerRefused ? $offerStatus : null);

        if (null !== $wakeStatus) {
            $availableAt = null !== $this->wakeRefusal ? $this->wakeAvailableAt() : $offer?->availableAt;
            $disabled = WakeRequestStatus::CapReached === $wakeStatus && null === $availableAt;

            return new RefreshToast(
                'wake-' . $this->clicks,
                ToastVariant::Warning,
                $disabled ? 'wake.disabled' : 'wake.' . $wakeStatus->value,
                $availableAt,
            );
        }

        if ($this->returned) {
            return new RefreshToast('returned-' . $this->clicks, ToastVariant::Info, 'outcome.returned');
        }

        $retryAt = $this->retryAt();

        if (null !== $retryAt) {
            return new RefreshToast('limited-' . $this->clicks, ToastVariant::Warning, 'limited.message', $retryAt);
        }

        $outcome = $this->outcome();

        if (null === $outcome || $this->isWakeDialogOpen()) {
            return null;
        }

        if ($outcome->isSettled()) {
            return new RefreshToast('result-' . $this->clicks, self::variantOf($outcome), 'outcome.' . $outcome->value);
        }

        // Still queued after the polling stopped: the result will show on a later reading.
        return $this->isPolling()
            ? null
            : new RefreshToast('result-' . $this->clicks, ToastVariant::Info, 'outcome.queued_late');
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
            && $attempt->getUpdatedAt()->addSeconds(self::POLL_SECONDS) > CarbonImmutable::instance(
                $this->clock->now()
            );
    }

    public function timeZone(): string
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getTimeZone() : 'UTC';
    }

    private function loadWakeOffer(): ?WakeRequestResult
    {
        if (null === $this->attemptId || $this->declinedAttemptId === $this->attemptId) {
            return null;
        }

        if (true !== $this->outcome()?->isWakeable()) {
            return null;
        }

        return $this->refreshes->wakeOffer($this->vehicle(), $this->attemptId);
    }

    /**
     * How to tell the user: a new reading is good news, a car that is not reachable is a warning, and what
     * needs an action of theirs or went wrong is danger.
     */
    private static function variantOf(RefreshOutcome $outcome): ToastVariant
    {
        return match ($outcome) {
            RefreshOutcome::Stored => ToastVariant::Success,
            RefreshOutcome::Queued,
            RefreshOutcome::Waking,
            RefreshOutcome::Unchanged,
            RefreshOutcome::Returned => ToastVariant::Info,
            RefreshOutcome::Asleep,
            RefreshOutcome::Offline,
            RefreshOutcome::Unavailable,
            RefreshOutcome::Busy,
            RefreshOutcome::Limited,
            RefreshOutcome::WakeTimeout,
            RefreshOutcome::WakeFailed,
            RefreshOutcome::WakeCapReached => ToastVariant::Warning,
            RefreshOutcome::NotConnected,
            RefreshOutcome::Rejected,
            RefreshOutcome::Failed,
            RefreshOutcome::WakeScopeMissing => ToastVariant::Danger,
        };
    }

    private function attempt(): ?RefreshAttempt
    {
        if (null === $this->attemptId) {
            return null;
        }

        if ($this->loadedAttempt?->getId()->toRfc4122() !== $this->attemptId) {
            $this->loadedAttempt = $this->attempts->findOf($this->vehicle(), $this->attemptId);
        }

        return $this->loadedAttempt;
    }

    /**
     * The vehicle of the props, checked against the user on every request: a Live Component action has no
     * #[IsGranted], and a foreign or unknown vehicle answers 404.
     */
    private function vehicle(): Vehicle
    {
        if (null !== $this->loadedVehicle) {
            return $this->loadedVehicle;
        }

        try {
            $vehicle = $this->entityManager->find(Vehicle::class, Ulid::fromString($this->vehicleId));
        } catch (\InvalidArgumentException) {
            $vehicle = null;
        }

        if (!$vehicle instanceof Vehicle || !$this->security->isGranted(VehiclePermission::Manage->value, $vehicle)) {
            throw new NotFoundHttpException();
        }

        return $this->loadedVehicle = $vehicle;
    }
}
