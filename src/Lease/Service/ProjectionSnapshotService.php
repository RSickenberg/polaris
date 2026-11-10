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
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Lease\Entity\ProjectionSnapshot;
use Polaris\Lease\Repository\ProjectionSnapshotRepository;
use Polaris\Lease\Service\Dto\ProjectionSnapshotPoint;
use Polaris\Lease\Service\Dto\SnapshotRecalculation;
use Polaris\Lease\Service\Dto\SnapshotsImportResult;
use Polaris\Lease\Service\Enum\SnapshotOutcome;
use Polaris\Lease\Service\Exception\LeaseTermsRejected;
use Polaris\Shared\Domain\OwnerMidnight;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * Keeps what Polaris projected for a vehicle's lease at the owner's midnight ({@see OwnerMidnight}): one snapshot per
 * lease and local day, the last run for the same midnight winning. Only the readings and verifications recorded up to
 * that midnight count ({@see LeaseTermsService::assessAt()}; a later verification is left out, not cut), so the
 * figures are the same whenever the job runs;
 * `computedAt` is the clock of the run. Nothing is saved without a contract, without data, or for a car returned at or
 * before that midnight. The scheduler, the console command and any listener only call this class.
 *
 * One save per lease runs at a time (a lock); the unique (lease, day) constraint backs it up.
 *
 * A row is a record of what Polaris projected at that midnight under the terms of then, until the terms change:
 * {@see self::recalculate()} then rewrites every row under the new ones. It is the only other thing that touches a
 * stored row.
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
    public function save(Vehicle $vehicle, OwnerMidnight $midnight): SnapshotOutcome
    {
        $contract = $this->leases->find($vehicle);

        if (null === $contract) {
            return SnapshotOutcome::SkippedNoContract;
        }

        $lock = $this->lockFactory->createLock(
            'lease_snapshot_' . $contract->getId()->toBase32(),
            self::LOCK_TTL_SECONDS,
        );

        if (!$lock->acquire()) {
            return SnapshotOutcome::Busy;
        }

        try {
            return $this->saveLocked($vehicle, $midnight);
        } finally {
            $lock->release();
        }
    }

    /**
     * Recalculates every snapshot of the lease under its current terms, after an edit that changed what a projection
     * depends on (lease start, end date, allowance, start odometer). A row becomes what Polaris would have projected at
     * its midnight with these terms and the readings stored now: each is assessed again with
     * {@see LeaseTermsService::assessAt()} at its stored cutoff. A row that cannot be assessed any more is deleted: no
     * cutoff (saved before the owner's midnight), a midnight before the lease start, no data, readings that do not fit
     * the terms, a lease returned by then. A midnight now past the lease end gives an `Ended` row.
     *
     * It holds the same lock as {@see self::save()} for the whole run and reads the terms once locked, so a midnight
     * snapshot is either finished before (and rewritten here) or starts after (and reads the new terms): no row is
     * left computed under the old ones. When the lock is taken it answers busy and the caller tries again. Done, it
     * marks the snapshots as following the terms it read, unless they were edited again meanwhile (another
     * recalculation is queued for that edit).
     *
     * @throws ORMException
     * @throws LockAcquiringException
     * @throws LockConflictedException
     * @throws LockReleasingException
     */
    public function recalculate(Vehicle $vehicle): SnapshotRecalculation
    {
        $contract = $this->leases->find($vehicle);

        if (null === $contract) {
            return new SnapshotRecalculation();
        }

        $lock = $this->lockFactory->createLock(
            'lease_snapshot_' . $contract->getId()->toBase32(),
            self::LOCK_TTL_SECONDS,
        );

        if (!$lock->acquire()) {
            return SnapshotRecalculation::busy();
        }

        try {
            return $this->recalculateLocked($vehicle, $contract, $lock);
        } finally {
            $lock->release();
        }
    }

    /**
     * Brings back snapshots exported earlier, as they were. A local day that already has a snapshot keeps it, a
     * snapshot saved before the cutoff and the pace were stored cannot be kept (the entity needs them), and without a
     * lease there is nothing to attach them to.
     *
     * @param list<ProjectionSnapshotPoint> $points
     *
     * @throws ORMException
     */
    public function import(Vehicle $vehicle, array $points): SnapshotsImportResult
    {
        $contract = $this->leases->find($vehicle);

        if (null === $contract) {
            return new SnapshotsImportResult(0, 0, \count($points));
        }

        $added = 0;
        $alreadyThere = 0;
        $skipped = 0;

        foreach ($points as $point) {
            if (null === $point->cutoffAt || null === $point->overallPace || null === $point->blendedPace) {
                ++$skipped;

                continue;
            }

            if (null !== $this->snapshots->findForDay($contract, $point->localDay)) {
                ++$alreadyThere;

                continue;
            }

            $this->entityManager->persist(new ProjectionSnapshot(
                leaseContract: $contract,
                localDay: $point->localDay,
                cutoffAt: $point->cutoffAt,
                computedAt: $point->computedAt,
                asOf: $point->asOf,
                driven: $point->driven,
                allowedToDate: $point->allowedToDate,
                projectedAtEnd: $point->projectedAtEnd,
                dailyBudget: $point->dailyBudget,
                riskLevel: $point->riskLevel,
                overallPace: $point->overallPace,
                recentPace: $point->recentPace,
                blendedPace: $point->blendedPace,
            ));
            ++$added;
        }

        if ($added > 0) {
            $this->entityManager->flush();
        }

        return new SnapshotsImportResult($added, $alreadyThere, $skipped);
    }

    /**
     * @throws ORMException
     * @throws LockConflictedException
     * @throws LockAcquiringException
     */
    private function recalculateLocked(
        Vehicle $vehicle,
        LeaseContract $contract,
        SharedLockInterface $lock,
    ): SnapshotRecalculation {
        // The terms may have been edited since the contract was loaded: read them now that nothing else saves.
        $this->entityManager->refresh($contract);
        $revision = $contract->getTermsRevision();
        $start = $contract->getTerm()->start();
        $computedAt = CarbonImmutable::instance($this->clock->now())->utc();
        $recomputed = 0;
        $deleted = 0;
        $unchanged = 0;

        foreach ($this->snapshots->findHistory($contract) as $row) {
            $lock->refresh();
            $cutoffAt = $row->getCutoffAt();

            if (null === $cutoffAt || $cutoffAt->lessThan($start)) {
                $this->entityManager->remove($row);
                ++$deleted;

                continue;
            }

            $assessment = $this->assessRow($vehicle, $cutoffAt);

            if (null === $assessment) {
                $this->entityManager->remove($row);
                ++$deleted;

                continue;
            }

            $projection = $assessment->projection;
            $same = $row->hasSameFigures(
                $cutoffAt,
                $projection->asOf,
                $projection->driven,
                $projection->allowedToDate,
                $projection->projectedAtEnd,
                $assessment->budget->perDay,
                $assessment->riskLevel,
                $projection->overallPace,
                $projection->recentPace,
                $projection->blendedPace,
            );

            if ($same) {
                ++$unchanged;

                continue;
            }

            $row->replace(
                cutoffAt: $cutoffAt,
                computedAt: $computedAt,
                asOf: $projection->asOf,
                driven: $projection->driven,
                allowedToDate: $projection->allowedToDate,
                projectedAtEnd: $projection->projectedAtEnd,
                dailyBudget: $assessment->budget->perDay,
                riskLevel: $assessment->riskLevel,
                overallPace: $projection->overallPace,
                recentPace: $projection->recentPace,
                blendedPace: $projection->blendedPace,
            );
            ++$recomputed;
        }

        $this->entityManager->flush();
        $this->markFollowing($contract, $revision);

        return new SnapshotRecalculation($recomputed, $deleted, $unchanged);
    }

    /**
     * The assessment a stored snapshot would get now; null when none would be saved.
     */
    private function assessRow(Vehicle $vehicle, CarbonImmutable $cutoffAt): ?LeaseAssessment
    {
        try {
            $assessment = $this->leases->assessAt($vehicle, $cutoffAt);
        } catch (LeaseTermsRejected) {
            return null;
        }

        if (!$assessment instanceof LeaseAssessment || !SnapshotPolicy::covers($assessment->status)) {
            return null;
        }

        return $assessment;
    }

    /**
     * Records that the snapshots follow the terms of $revision. Never moves backwards, so an edit made while the
     * recalculation ran (a higher revision already stored) stays stale until its own recalculation is done.
     *
     * @throws ORMException
     */
    private function markFollowing(LeaseContract $contract, int $revision): void
    {
        $this->entityManager->createQueryBuilder()
            ->update(LeaseContract::class, 'c')
            ->set('c.snapshotsRevision', ':revision')
            ->where('c.id = :id')
            ->andWhere('c.snapshotsRevision < :revision')
            ->setParameter('revision', $revision)
            ->setParameter('id', $contract->getId(), UlidType::NAME)
            ->getQuery()
            ->execute();
        $this->entityManager->refresh($contract);
    }

    /**
     * @throws ORMException
     */
    private function saveLocked(Vehicle $vehicle, OwnerMidnight $midnight): SnapshotOutcome
    {
        try {
            $assessment = $this->leases->assessAt($vehicle, $midnight->at);
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
            return LeaseStatus::Returned === $assessment->status
                ? SnapshotOutcome::SkippedReturned
                : SnapshotOutcome::SkippedNotAssessable;
        }

        return $this->store($vehicle, $midnight, $assessment);
    }

    /**
     * @throws ORMException
     */
    private function store(Vehicle $vehicle, OwnerMidnight $midnight, LeaseAssessment $assessment): SnapshotOutcome
    {
        $contract = $this->leases->find($vehicle);
        \assert(null !== $contract);

        $computedAt = CarbonImmutable::instance($this->clock->now())->utc();
        $projection = $assessment->projection;
        $existing = $this->snapshots->findForDay($contract, $midnight->localDay);

        if (null !== $existing) {
            $existing->replace(
                cutoffAt: $midnight->at,
                computedAt: $computedAt,
                asOf: $projection->asOf,
                driven: $projection->driven,
                allowedToDate: $projection->allowedToDate,
                projectedAtEnd: $projection->projectedAtEnd,
                dailyBudget: $assessment->budget->perDay,
                riskLevel: $assessment->riskLevel,
                overallPace: $projection->overallPace,
                recentPace: $projection->recentPace,
                blendedPace: $projection->blendedPace,
            );
            $this->entityManager->flush();

            return SnapshotOutcome::Updated;
        }

        try {
            $this->entityManager->persist(new ProjectionSnapshot(
                leaseContract: $contract,
                localDay: $midnight->localDay,
                cutoffAt: $midnight->at,
                computedAt: $computedAt,
                asOf: $projection->asOf,
                driven: $projection->driven,
                allowedToDate: $projection->allowedToDate,
                projectedAtEnd: $projection->projectedAtEnd,
                dailyBudget: $assessment->budget->perDay,
                riskLevel: $assessment->riskLevel,
                overallPace: $projection->overallPace,
                recentPace: $projection->recentPace,
                blendedPace: $projection->blendedPace,
            ));
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Another writer stored the row of the day after the lookup above. The entity manager is closed after this.
            return SnapshotOutcome::Concurrent;
        }

        return SnapshotOutcome::Saved;
    }
}
