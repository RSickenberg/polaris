<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service;

use Doctrine\ORM\EntityManagerInterface;
use Polaris\Tesla\Entity\OAuthToken;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Service\Enum\VehicleState;
use Polaris\Tesla\Service\Enum\WakeAccess;
use Polaris\Tesla\Service\Exception\FleetApiError;
use Polaris\Tesla\Service\Exception\TeslaNotConnected;
use Polaris\Tesla\Service\Exception\WakeScopeMissing;
use Polaris\Vehicle\Domain\Vin;

/**
 * Wakes one vehicle and checks whether it came online. Whether to wake, how often and for how long to wait are
 * the caller's rules (the Fetching module); this class only talks to Tesla and says what the scope allows.
 */
final readonly class TeslaVehicleWaker
{
    public function __construct(
        private VehicleCommandClient $commands,
        private VehicleClient $vehicles,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Whether the stored connection may wake a vehicle, read from the stored scopes: nothing is sent to Tesla.
     */
    public function access(TeslaApp $app): WakeAccess
    {
        $token = $this->entityManager->getRepository(OAuthToken::class)->findOneBy(['teslaApp' => $app]);

        if (!$token instanceof OAuthToken || !$token->isConnected()) {
            return WakeAccess::NotConnected;
        }

        return $token->canWake() ? WakeAccess::Allowed : WakeAccess::ScopeMissing;
    }

    /**
     * @throws TeslaNotConnected
     * @throws WakeScopeMissing  when Tesla answers 401 or 403
     * @throws FleetApiError     any other failure, 408 included
     */
    public function wake(TeslaApp $app, Vin $vin): void
    {
        try {
            $this->commands->wakeUp($app, $vin);
        } catch (FleetApiError $failure) {
            throw $failure->isAccessRefused() ? WakeScopeMissing::notGranted($failure) : $failure;
        }
    }

    /**
     * Whether Tesla lists the vehicle as online, from `GET /api/1/vehicles` (the list, not the single vehicle).
     * A vehicle missing from the first page counts as not online.
     *
     * @throws TeslaNotConnected
     * @throws FleetApiError
     */
    public function isOnline(TeslaApp $app, Vin $vin): bool
    {
        foreach ($this->vehicles->listVehicles($app) as $summary) {
            if ($summary->vin->value() === $vin->value()) {
                return VehicleState::Online === $summary->state;
            }
        }

        return false;
    }
}
