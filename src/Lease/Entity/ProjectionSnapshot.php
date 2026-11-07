<?php

declare(strict_types=1);

namespace Polaris\Lease\Entity;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Polaris\Lease\Domain\Pace;
use Polaris\Lease\Domain\RiskLevel;
use Polaris\Lease\Repository\ProjectionSnapshotRepository;
use Polaris\Shared\Doctrine\Type\CarbonDateImmutableType;
use Polaris\Shared\Domain\Distance;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * What Polaris projected for a lease at the owner's midnight M: the figures of the assessment with only the readings up
 * to M, kept so that past projections can be compared with what was driven. One row per lease and local day; a run for
 * the same midnight replaces the figures (the last wins). It is a record: editing the terms or adding a reading later
 * never changes a row.
 *
 * The local day is the calendar date M OPENS (M is its first instant): the row of 2026-11-07 is the projection at the
 * very start of 7 November, so it describes the end of the 6th. "Today's snapshot" is the row with today's date.
 * Rows saved before the daily jobs followed the owner's midnight (UTC day label, taken at 23:50 UTC) have no cutoff and
 * no pace.
 *
 * Columns hold scalars (metres, metres per day); the getters return the Domain value objects.
 */
#[ORM\Entity(repositoryClass: ProjectionSnapshotRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_projection_snapshot_lease_day', columns: ['lease_contract_id', 'local_day'])]
class ProjectionSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private LeaseContract $leaseContract;

    /** The local calendar date of the owner that the midnight {@see $cutoffAt} opens, as midnight UTC (a label). */
    #[ORM\Column(type: CarbonDateImmutableType::NAME)]
    private CarbonImmutable $localDay;

    /**
     * The instant M of the owner's midnight the figures are taken at: only readings up to it count. UTC.
     * Null only on rows saved before the daily jobs followed the owner's midnight.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?CarbonImmutable $cutoffAt = null;

    /** When it was saved, UTC. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private CarbonImmutable $computedAt;

    /** The instant the figures are taken at: the latest reading, which can be days older than {@see $computedAt}. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private CarbonImmutable $asOf;

    #[ORM\Column]
    private int $drivenMetres;

    #[ORM\Column]
    private int $allowedToDateMetres;

    #[ORM\Column]
    private int $projectedAtEndMetres;

    #[ORM\Column]
    private int $dailyBudgetMetres;

    #[ORM\Column(length: 8, enumType: RiskLevel::class)]
    private RiskLevel $riskLevel;

    /**
     * The pace since the lease start, in metres per day. The driver keeps 14 significant digits, so it equals the
     * {@see Pace} the projection used to within 1e-9. Null only on rows saved before the pace was stored.
     */
    #[ORM\Column(nullable: true)]
    private ?float $overallPaceMetresPerDay = null;

    /** The pace over the 30 days before `asOf`. Null before 30 days of lease, and on rows saved before the pace was stored. */
    #[ORM\Column(nullable: true)]
    private ?float $recentPaceMetresPerDay = null;

    /** The pace the projection used: the weighted mix of both, or the overall pace alone without a recent one. */
    #[ORM\Column(nullable: true)]
    private ?float $blendedPaceMetresPerDay = null;

    /**
     * @throws \InvalidArgumentException when the local day is not midnight UTC
     */
    public function __construct(
        LeaseContract $leaseContract,
        CarbonImmutable $localDay,
        CarbonImmutable $cutoffAt,
        CarbonImmutable $computedAt,
        CarbonImmutable $asOf,
        Distance $driven,
        Distance $allowedToDate,
        Distance $projectedAtEnd,
        Distance $dailyBudget,
        RiskLevel $riskLevel,
        Pace $overallPace,
        ?Pace $recentPace,
        Pace $blendedPace,
    ) {
        if (0 !== $localDay->getOffset() || '00:00:00' !== $localDay->format('H:i:s')) {
            throw new \InvalidArgumentException(\sprintf('A snapshot day must be midnight UTC, got %s.', $localDay->toIso8601String()));
        }

        $this->id = new Ulid();
        $this->leaseContract = $leaseContract;
        $this->localDay = $localDay;
        $this->replace($cutoffAt, $computedAt, $asOf, $driven, $allowedToDate, $projectedAtEnd, $dailyBudget, $riskLevel, $overallPace, $recentPace, $blendedPace);
    }

    /**
     * Replaces the figures of the day: a later run for the same midnight wins.
     */
    public function replace(
        CarbonImmutable $cutoffAt,
        CarbonImmutable $computedAt,
        CarbonImmutable $asOf,
        Distance $driven,
        Distance $allowedToDate,
        Distance $projectedAtEnd,
        Distance $dailyBudget,
        RiskLevel $riskLevel,
        Pace $overallPace,
        ?Pace $recentPace,
        Pace $blendedPace,
    ): void {
        $this->cutoffAt = $cutoffAt->utc();
        $this->computedAt = $computedAt->utc();
        $this->asOf = $asOf->utc();
        $this->drivenMetres = $driven->metres();
        $this->allowedToDateMetres = $allowedToDate->metres();
        $this->projectedAtEndMetres = $projectedAtEnd->metres();
        $this->dailyBudgetMetres = $dailyBudget->metres();
        $this->riskLevel = $riskLevel;
        $this->overallPaceMetresPerDay = $overallPace->metresPerDay();
        $this->recentPaceMetresPerDay = $recentPace?->metresPerDay();
        $this->blendedPaceMetresPerDay = $blendedPace->metresPerDay();
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getLeaseContract(): LeaseContract
    {
        return $this->leaseContract;
    }

    /** The local calendar date the midnight {@see self::getCutoffAt()} opens, as midnight UTC. */
    public function getLocalDay(): CarbonImmutable
    {
        return $this->localDay;
    }

    /** The owner's midnight the figures are taken at; null for a row saved before it was kept. */
    public function getCutoffAt(): ?CarbonImmutable
    {
        return $this->cutoffAt;
    }

    public function getComputedAt(): CarbonImmutable
    {
        return $this->computedAt;
    }

    public function getAsOf(): CarbonImmutable
    {
        return $this->asOf;
    }

    public function getDriven(): Distance
    {
        return Distance::fromMetres($this->drivenMetres);
    }

    public function getAllowedToDate(): Distance
    {
        return Distance::fromMetres($this->allowedToDateMetres);
    }

    public function getProjectedAtEnd(): Distance
    {
        return Distance::fromMetres($this->projectedAtEndMetres);
    }

    public function getDailyBudget(): Distance
    {
        return Distance::fromMetres($this->dailyBudgetMetres);
    }

    public function getRiskLevel(): RiskLevel
    {
        return $this->riskLevel;
    }

    public function getOverallPace(): ?Pace
    {
        return null === $this->overallPaceMetresPerDay ? null : Pace::fromMetresPerDay($this->overallPaceMetresPerDay);
    }

    public function getRecentPace(): ?Pace
    {
        return null === $this->recentPaceMetresPerDay ? null : Pace::fromMetresPerDay($this->recentPaceMetresPerDay);
    }

    public function getBlendedPace(): ?Pace
    {
        return null === $this->blendedPaceMetresPerDay ? null : Pace::fromMetresPerDay($this->blendedPaceMetresPerDay);
    }
}
