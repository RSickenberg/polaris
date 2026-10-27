<?php

declare(strict_types=1);

namespace Polaris\Fetching\Service;

use Carbon\CarbonImmutable;
use Polaris\Fetching\Domain\WakeCapStatus;
use Polaris\Fetching\Domain\WakeWindow;
use Polaris\Fetching\Repository\RefreshAttemptRepository;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Vehicle\Entity\Vehicle;

/**
 * How many wakes a vehicle has left. The cap is the owner's setting ({@see TeslaApp::getDailyWakeCap()}); a wake
 * counts from the moment wake_up was sent, for 24 hours. The count is read from the refreshes themselves, so
 * the fetch, which sends wake_up under the lock of the vehicle, decides without a race.
 */
final readonly class WakeCap
{
    public function __construct(private RefreshAttemptRepository $attempts)
    {
    }

    public function status(Vehicle $vehicle, TeslaApp $app, CarbonImmutable $now): WakeCapStatus
    {
        return WakeWindow::capStatus(
            $this->attempts->wakesSince($vehicle, WakeWindow::capStart($now)),
            $app->getDailyWakeCap(),
        );
    }
}
