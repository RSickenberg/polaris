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
use Polaris\Lease\Service\Dto\ProjectionSnapshotPoint;
use Polaris\Lease\Service\Dto\SnapshotsImportResult;
use Polaris\Lease\Service\Enum\SnapshotOutcome;
use Polaris\Lease\Service\Exception\LeaseTermsRejected;
use Polaris\Shared\Domain\OwnerMidnight;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\LockFactory;

/**
 * Keeps what Polaris projected for a vehicle's lease at the owner's midnight ({@see OwnerMidnight}): one snapshot per
 * lease and local day, the last run for the same midnight winning. Only the readings and verifications recorded up to
 * that midnight count ({@see LeaseTermsService::assessAt()}; a later verification is left out, not cut), so the
 * figures are the same whenever the job runs;
 * `computedAt` is the clock of the run. Nothing is saved without a contract, without data, or for a car returned at or
 * before that midnight. The scheduler, the console command and any listener only call this class.
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
