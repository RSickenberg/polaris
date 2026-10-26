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
use Polaris\Fetching\Service\ManualRefreshService;
use Polaris\Vehicle\Entity\Vehicle;
use Polaris\Vehicle\Service\Enum\VehiclePermission;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
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

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RefreshAttemptRepository $attempts,
        private readonly ManualRefreshService $refreshes,
        private readonly Security $security,
        private readonly ClockInterface $clock,
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

        $this->attemptId = $result->attemptId?->toRfc4122();
        $this->limitedUntil = $result->retryAfter?->getTimestamp();
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
     * Whether the fetch is queued and recent enough to wait for.
     */
    public function isPolling(): bool
    {
        $attempt = $this->attempt();

        return RefreshOutcome::Queued === $attempt?->getOutcome()
            && $attempt->getRequestedAt()->addSeconds(self::POLL_SECONDS) > CarbonImmutable::instance($this->clock->now());
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
