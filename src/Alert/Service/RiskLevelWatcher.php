<?php

declare(strict_types=1);

namespace Polaris\Alert\Service;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Polaris\Alert\Domain\AlertDecision;
use Polaris\Alert\Domain\RiskChangeRule;
use Polaris\Alert\Entity\AlertLog;
use Polaris\Alert\Entity\AlertType;
use Polaris\Alert\Repository\AlertLogRepository;
use Polaris\Alert\Service\Dto\RiskLevelChange;
use Polaris\Alert\Service\Enum\RiskCheckOutcome;
use Polaris\Extension\RiskAlertNotifier;
use Polaris\Lease\Domain\LeaseAssessment;
use Polaris\Lease\Domain\LeaseStatus;
use Polaris\Lease\Domain\RiskLevel;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\LockFactory;

/**
 * Compares the risk level of a vehicle with the last one logged, and tells the {@see RiskAlertNotifier} when it got worse.
 *
 * The newest {@see AlertLog} is the known level, so the same level is never told twice. A first assessment, or a level
 * that went down, is logged without a call. A draft or ended lease, no contract and no readings are not assessed.
 *
 * One check per vehicle runs at a time (a scheduled fetch and a manual reading may land together): the log is read
 * under a lock, so the second check sees the row of the first.
 */
final readonly class RiskLevelWatcher
{
    /** Long enough for a notifier to answer, short enough for a lock left by a killed worker to free itself. */
    private const int LOCK_TTL_SECONDS = 60;

    public function __construct(
        private LeaseTermsService $leases,
        private AlertLogRepository $logs,
        private EntityManagerInterface $entityManager,
        private RiskAlertNotifier $notifier,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ORMException
     * @throws NonUniqueResultException
     * @throws LockAcquiringException
     * @throws LockConflictedException
     * @throws LockReleasingException
     */
    public function check(Vehicle $vehicle): RiskCheckOutcome
    {
        $lock = $this->lockFactory->createLock('risk_alert_' . $vehicle->getId()->toBase32(), self::LOCK_TTL_SECONDS);

        if (!$lock->acquire()) {
            return RiskCheckOutcome::Busy;
        }

        try {
            return $this->checkLocked($vehicle);
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws ORMException
     * @throws NonUniqueResultException
     */
    private function checkLocked(Vehicle $vehicle): RiskCheckOutcome
    {
        $assessment = $this->leases->assess($vehicle);

        if (!$assessment instanceof LeaseAssessment || LeaseStatus::Active !== $assessment->status) {
            return RiskCheckOutcome::NotAssessable;
        }

        // Read once the lock is held: the check that held it before may have logged this very level.
        $last = $this->logs->findLatest($vehicle);
        $current = $assessment->riskLevel;
        $now = CarbonImmutable::instance($this->clock->now())->utc();

        switch (RiskChangeRule::decide(null === $last ? null : self::rank($last->getRiskLevel()), self::rank($current))) {
            case AlertDecision::Nothing:
                return RiskCheckOutcome::Unchanged;

            case AlertDecision::Baseline:
                $this->entityManager->persist(new AlertLog($vehicle, $now, AlertType::Baseline, $current));
                $this->entityManager->flush();

                return RiskCheckOutcome::Recorded;

            case AlertDecision::Notify:
                \assert(null !== $last);
                $channel = $this->notifier->notify(new RiskLevelChange($vehicle, $last->getRiskLevel(), $current, $assessment));
                $this->entityManager->persist(new AlertLog($vehicle, $now, AlertType::RiskIncreased, $current, $channel));
                $this->entityManager->flush();

                return RiskCheckOutcome::Notified;
        }
    }

    /**
     * 0 is the best level, a higher rank is worse.
     */
    private static function rank(RiskLevel $level): int
    {
        return match ($level) {
            RiskLevel::Ok => 0,
            RiskLevel::Watch => 1,
            RiskLevel::Over => 2,
        };
    }
}
