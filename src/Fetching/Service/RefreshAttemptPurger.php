<?php

declare(strict_types=1);

namespace Polaris\Fetching\Service;

use Carbon\CarbonImmutable;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Fetching\Entity\RefreshAttempt;
use Polaris\Fetching\Repository\RefreshAttemptRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Deletes the {@see RefreshAttempt}s of an owner that are old enough, at the owner's midnight (the retention is a
 * rolling 24 hours, only the time it runs follows the owner's day). A page needs one only while it polls for the result
 * (2 minutes) and to be shown it once; what the car answered is in the logs, and a reading that was stored is in
 * `odometer_reading`. A day keeps the last answers for a look at the logs without the table growing for ever.
 *
 * The wake cap counts the wakes of the last 24 hours from these rows (`wokeAt`), so an attempt is kept while its wake is
 * younger than the retention too, even if it was requested earlier.
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
     * @param Ulid $ownerId the owner whose attempts are purged
     *
     * @return int how many attempts were deleted
     *
     * @throws ORMException
     */
    public function purgeOf(Ulid $ownerId): int
    {
        $before = CarbonImmutable::instance($this->clock->now())->subHours(self::RETENTION_HOURS);
        $deleted = $this->attempts->deleteOfOwnerBefore($ownerId, $before);

        if ($deleted > 0) {
            $this->logger->info('Old manual refreshes deleted.', ['count' => $deleted, 'before' => $before->toIso8601String()]);
        }

        return $deleted;
    }
}
