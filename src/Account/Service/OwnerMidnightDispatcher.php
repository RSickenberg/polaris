<?php

declare(strict_types=1);

namespace Polaris\Account\Service;

use Carbon\CarbonImmutable;
use Polaris\Account\Entity\User;
use Polaris\Account\Message\OwnerMidnightPassed;
use Polaris\Account\Repository\UserRepository;
use Polaris\Shared\Domain\OwnerMidnight;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Every daily job runs at the owner's local midnight, not at a UTC time. Called every minute: for each user it works
 * out the latest midnight M whose jobs are due ({@see OwnerMidnight::latestDue()}: M plus 5 minutes has passed) and,
 * if that local day has not run yet, queues one {@see OwnerMidnightPassed}. The local days are converted from the
 * owner's time zone every time, so daylight saving and a changed zone need no restart.
 *
 * "Not run yet" is the user's `lastMidnightDay`, advanced by one compare-and-set UPDATE before the message is queued
 * (as `FetchSlotRun` does for a slot): of two ticks or two workers exactly one wins, and a doubled tick or a restart
 * runs nothing twice. After an outage only the latest missed midnight runs, never one per missed day. If the message
 * cannot be queued, the claim is given back.
 */
final readonly class OwnerMidnightDispatcher
{
    public function __construct(
        private UserRepository $users,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int the number of owners whose midnight was queued
     *
     * @throws ExceptionInterface
     */
    public function dispatch(): int
    {
        $now = CarbonImmutable::instance($this->clock->now());
        $queued = 0;

        foreach ($this->users->findAll() as $user) {
            try {
                $midnight = OwnerMidnight::latestDue($now, $user->getTimeZone());
            } catch (\InvalidArgumentException $e) {
                $this->logger->warning('An owner has a time zone that is not valid; their daily jobs are skipped.', ['exception' => $e]);

                continue;
            }

            if ($this->queue($user, $midnight)) {
                ++$queued;
            }
        }

        return $queued;
    }

    /**
     * @throws ExceptionInterface
     */
    private function queue(User $user, OwnerMidnight $midnight): bool
    {
        $previous = $user->getLastMidnightDay();

        if (!$this->users->claimMidnight($user, $midnight->localDay)) {
            return false;
        }

        try {
            $this->bus->dispatch(new OwnerMidnightPassed($user->getId()->toRfc4122(), $midnight->localDay->format('Y-m-d'), $midnight->at->getTimestamp()));
        } catch (ExceptionInterface $e) {
            $this->users->releaseMidnight($user, $midnight->localDay, $previous);

            throw $e;
        }

        return true;
    }
}
