<?php

declare(strict_types=1);

namespace Polaris\Fetching\Service;

use Carbon\CarbonImmutable;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Fetching\Entity\RefreshAttempt;
use Polaris\Fetching\Repository\RefreshAttemptRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Deletes the {@see RefreshAttempt}s that are old enough. A page needs one only while it polls for the result
 * (2 minutes) and to be shown it once; what the car answered is in the logs, and a reading that was stored is in
 * `odometer_reading`. A day keeps the last answers for a look at the logs without the table growing for ever.
 */
final readonly class RefreshAttemptPurger
{
    public const int RETENTION_HOURS = 24;

    public function __construct(
        private RefreshAttemptRepository $attempts,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int how many attempts were deleted
     *
     * @throws ORMException
     */
    public function purge(): int
    {
        $before = CarbonImmutable::instance($this->clock->now())->subHours(self::RETENTION_HOURS);
        $deleted = $this->attempts->deleteRequestedBefore($before);

        if ($deleted > 0) {
            $this->logger->info('Old manual refreshes deleted.', ['count' => $deleted, 'before' => $before->toIso8601String()]);
        }

        return $deleted;
    }
}
