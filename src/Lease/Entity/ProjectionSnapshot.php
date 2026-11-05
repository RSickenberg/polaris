<?php

declare(strict_types=1);

namespace Polaris\Lease\Entity;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Polaris\Lease\Domain\RiskLevel;
use Polaris\Lease\Repository\ProjectionSnapshotRepository;
use Polaris\Shared\Doctrine\Type\CarbonDateImmutableType;
use Polaris\Shared\Domain\Distance;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * What Polaris projected for a lease on a UTC day: the figures of the assessment, kept so that past projections can be
 * compared with what was driven. One row per lease and day; a run the same day replaces the figures (the last wins).
 * It is a record: editing the terms or adding a reading later never changes a row.
 *
 * Columns hold scalars (metres); the getters return the Domain value objects.
 */
#[ORM\Entity(repositoryClass: ProjectionSnapshotRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_projection_snapshot_lease_day', columns: ['lease_contract_id', 'day'])]
class ProjectionSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private LeaseContract $leaseContract;

    /** The UTC day the snapshot belongs to (taken from the clock when it was computed), at midnight UTC. */
    #[ORM\Column(type: CarbonDateImmutableType::NAME)]
    private CarbonImmutable $day;

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
     * @throws \InvalidArgumentException when the day is not midnight UTC
     */
    public function __construct(
        LeaseContract $leaseContract,
        CarbonImmutable $day,
        CarbonImmutable $computedAt,
        CarbonImmutable $asOf,
        Distance $driven,
        Distance $allowedToDate,
        Distance $projectedAtEnd,
        Distance $dailyBudget,
        RiskLevel $riskLevel,
    ) {
        if (0 !== $day->getOffset() || '00:00:00' !== $day->format('H:i:s')) {
            throw new \InvalidArgumentException(\sprintf('A snapshot day must be midnight UTC, got %s.', $day->toIso8601String()));
        }

        $this->id = new Ulid();
        $this->leaseContract = $leaseContract;
        $this->day = $day;
        $this->replace($computedAt, $asOf, $driven, $allowedToDate, $projectedAtEnd, $dailyBudget, $riskLevel);
    }

    /**
     * Replaces the figures of the day: a later run of the same day wins.
     */
    public function replace(
        CarbonImmutable $computedAt,
        CarbonImmutable $asOf,
        Distance $driven,
        Distance $allowedToDate,
        Distance $projectedAtEnd,
        Distance $dailyBudget,
        RiskLevel $riskLevel,
    ): void {
        $this->computedAt = $computedAt->utc();
        $this->asOf = $asOf->utc();
        $this->drivenMetres = $driven->metres();
        $this->allowedToDateMetres = $allowedToDate->metres();
        $this->projectedAtEndMetres = $projectedAtEnd->metres();
        $this->dailyBudgetMetres = $dailyBudget->metres();
        $this->riskLevel = $riskLevel;
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getLeaseContract(): LeaseContract
    {
        return $this->leaseContract;
    }

    public function getDay(): CarbonImmutable
    {
        return $this->day;
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
}
