<?php

declare(strict_types=1);

namespace Polaris\Lease\Service;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Lease\Domain\LeaseAssessment;
use Polaris\Lease\Domain\LeaseStatus;
use Polaris\Lease\Domain\NoData;
use Polaris\Lease\Domain\SnapshotPolicy;
use Polaris\Lease\Entity\ProjectionSnapshot;
use Polaris\Lease\Repository\ProjectionSnapshotRepository;
use Polaris\Lease\Service\Enum\SnapshotOutcome;
use Polaris\Lease\Service\Exception\LeaseTermsRejected;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\LockFactory;

/**
 * Keeps what Polaris projects for a vehicle's lease today: one snapshot per lease and UTC day, the last run of the day
 * winning. The assessment is the one the dashboard shows ({@see LeaseTermsService::assess()}). Nothing is saved without
 * a contract, without data, or once the car is returned. The scheduler, the console command and any listener only call
 * this class.
 *
 * One save per lease runs at a time (a lock); the unique (lease, day) constraint backs it up.
 */
final readonly class ProjectionSnapshotService
{
    /** Long enough for an assessment, short enough for a lock left by a killed worker to free itself. */
    private const int LOCK_TTL_SECONDS = 60;

    public function __construct(
        private LeaseTermsService $leases,
        private ProjectionSnapshotRepository $snapshots,
        private EntityManagerInterface $entityManager,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ORMException
     * @throws LockAcquiringException
     * @throws LockConflictedException
     * @throws LockReleasingException
     */
    public function save(Vehicle $vehicle): SnapshotOutcome
    {
        $contract = $this->leases->find($vehicle);

        if (null === $contract) {
            return SnapshotOutcome::SkippedNoContract;
        }

        $lock = $this->lockFactory->createLock('lease_snapshot_' . $contract->getId()->toBase32(), self::LOCK_TTL_SECONDS);

        if (!$lock->acquire()) {
            return SnapshotOutcome::Busy;
        }

        try {
            return $this->saveLocked($vehicle);
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws ORMException
     */
    private function saveLocked(Vehicle $vehicle): SnapshotOutcome
    {
        try {
            $assessment = $this->leases->assess($vehicle);
        } catch (LeaseTermsRejected) {
            return SnapshotOutcome::SkippedNotAssessable;
        }

        if (null === $assessment) {
            return SnapshotOutcome::SkippedNoContract;
        }

        if ($assessment instanceof NoData) {
            return SnapshotOutcome::SkippedNoData;
        }

        if (!SnapshotPolicy::covers($assessment->status)) {
            return LeaseStatus::Returned === $assessment->status ? SnapshotOutcome::SkippedReturned : SnapshotOutcome::SkippedNotAssessable;
        }

        return $this->store($vehicle, $assessment);
    }

    /**
     * @throws ORMException
     */
    private function store(Vehicle $vehicle, LeaseAssessment $assessment): SnapshotOutcome
    {
        $contract = $this->leases->find($vehicle);
        \assert(null !== $contract);

        $day = SnapshotPolicy::dayOf($this->clock);
        $computedAt = CarbonImmutable::instance($this->clock->now())->utc();
        $projection = $assessment->projection;
        $existing = $this->snapshots->findForDay($contract, $day);

        if (null !== $existing) {
            $existing->replace($computedAt, $projection->asOf, $projection->driven, $projection->allowedToDate, $projection->projectedAtEnd, $assessment->budget->perDay, $assessment->riskLevel);
            $this->entityManager->flush();

            return SnapshotOutcome::Updated;
        }

        try {
            $this->entityManager->persist(new ProjectionSnapshot($contract, $day, $computedAt, $projection->asOf, $projection->driven, $projection->allowedToDate, $projection->projectedAtEnd, $assessment->budget->perDay, $assessment->riskLevel));
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Another writer stored the day's row after the lookup above. The entity manager is closed after this.
            return SnapshotOutcome::Concurrent;
        }

        return SnapshotOutcome::Saved;
    }
}
