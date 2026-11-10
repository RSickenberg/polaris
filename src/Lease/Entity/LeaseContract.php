<?php

declare(strict_types=1);

namespace Polaris\Lease\Entity;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\QuietHours;
use Polaris\Lease\Domain\Tolerance;
use Polaris\Shared\Doctrine\Type\CarbonDateImmutableType;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\Money;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * The terms of a vehicle's lease.
 *
 * Columns hold scalars (metres, minor units, basis points); the constructor and getters
 * take and return the Domain value objects, which validate every value.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_lease_contract_vehicle', columns: ['vehicle_id'])]
class LeaseContract
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Vehicle $vehicle;

    #[ORM\Column(type: CarbonDateImmutableType::NAME)]
    private CarbonImmutable $startDate;

    #[ORM\Column(type: CarbonDateImmutableType::NAME)]
    private CarbonImmutable $endDate;

    #[ORM\Column(length: 16, enumType: EndDateConvention::class)]
    private EndDateConvention $endDateConvention;

    #[ORM\Column]
    private int $allowanceMetres;

    #[ORM\Column]
    private int $startOdometerMetres;

    #[ORM\Column]
    private int $excessCostPerKmMinor;

    #[ORM\Column(length: 3, enumType: Currency::class)]
    private Currency $currency;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $toleranceBasisPoints;

    /**
     * The UTC instant the car went back to the bank (the start of the day after the date the user gave, in their
     * time zone), or null while the lease runs. Editing the terms never touches it.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?CarbonImmutable $returnedAt = null;

    /**
     * Counts the edits of what a projection depends on (lease start, end date, allowance, start odometer): the
     * stored snapshots follow it, see {@see self::$snapshotsRevision}.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $termsRevision = 0;

    /**
     * The {@see self::$termsRevision} the stored projection snapshots were last recalculated for. While it is behind,
     * the snapshots describe other terms and the dashboard does not compare with them.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $snapshotsRevision = 0;

    /**
     * The usual stop time and start time of driving, local "HH:MM" in the owner's time zone; both set or both null.
     * They only shape how the odometer is read between two readings (see {@see QuietHours}), so no projection
     * snapshot depends on them and editing them does not touch {@see self::$termsRevision}.
     */
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $quietFrom = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $quietUntil = null;

    /**
     * Whether the "Today" row of the comparison panels is measured against what was expected for the whole day (as
     * recorded at its midnight) rather than prorated to the latest reading. A display choice: no snapshot depends on
     * it.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $todayWholeDay = false;

    public function __construct(
        Vehicle $vehicle,
        LeaseTerm $term,
        Allowance $allowance,
        Distance $startOdometer,
        Money $excessCostPerKm,
        Tolerance $tolerance,
    ) {
        $this->id = new Ulid();
        $this->vehicle = $vehicle;
        $this->apply($term, $allowance, $startOdometer, $excessCostPerKm, $tolerance);
    }

    /**
     * Replaces the terms in place: a vehicle has one contract, and an edit corrects it.
     *
     * @return bool whether something a projection depends on changed (lease start, end date and its convention,
     *              allowance, start odometer); the excess cost and the tolerance do not count. The snapshots are
     *              then stale until they are recalculated.
     */
    public function updateTerms(
        LeaseTerm $term,
        Allowance $allowance,
        Distance $startOdometer,
        Money $excessCostPerKm,
        Tolerance $tolerance,
    ): bool {
        $changed = !$this->startDate->equalTo($term->start())
            || !$this->endDate->equalTo($term->end())
            || $this->endDateConvention !== $term->endDateConvention()
            || $this->allowanceMetres !== $allowance->total()->metres()
            || $this->startOdometerMetres !== $startOdometer->metres();

        $this->apply($term, $allowance, $startOdometer, $excessCostPerKm, $tolerance);

        if ($changed) {
            ++$this->termsRevision;
        }

        return $changed;
    }

    /** Whether the projection snapshots were taken under terms that have been edited since. */
    public function hasStaleSnapshots(): bool
    {
        return $this->snapshotsRevision < $this->termsRevision;
    }

    public function getTermsRevision(): int
    {
        return $this->termsRevision;
    }

    private function apply(
        LeaseTerm $term,
        Allowance $allowance,
        Distance $startOdometer,
        Money $excessCostPerKm,
        Tolerance $tolerance,
    ): void {
        if ($excessCostPerKm->isNegative()) {
            throw new \InvalidArgumentException('The excess cost per km cannot be negative.');
        }

        $this->startDate = $term->start();
        $this->endDate = $term->end();
        $this->endDateConvention = $term->endDateConvention();
        $this->allowanceMetres = $allowance->total()->metres();
        $this->startOdometerMetres = $startOdometer->metres();
        $this->excessCostPerKmMinor = $excessCostPerKm->minorAmount();
        $this->currency = $excessCostPerKm->currency();
        $this->toleranceBasisPoints = $tolerance->basisPoints();
    }

    /**
     * @throws \InvalidArgumentException when the instant is not in UTC
     */
    public function markReturned(CarbonImmutable $returnedAt): void
    {
        if (0 !== $returnedAt->getOffset()) {
            throw new \InvalidArgumentException(
                \sprintf('A return must be stored at a UTC instant, got %s.', $returnedAt->toIso8601String())
            );
        }

        $this->returnedAt = $returnedAt;
    }

    public function undoReturn(): void
    {
        $this->returnedAt = null;
    }

    /**
     * @throws \InvalidArgumentException when only one time is given, or they are not valid and different
     */
    public function changeQuietHours(?string $from, ?string $until): void
    {
        if (null !== $from && null !== $until) {
            // Validates both times; the zone does not matter here.
            QuietHours::between($from, $until, 'UTC');
        } elseif (null !== $from || null !== $until) {
            throw new \InvalidArgumentException('The quiet hours need both a stop time and a start time.');
        }

        $this->quietFrom = $from;
        $this->quietUntil = $until;
    }

    public function changeTodayWholeDay(bool $todayWholeDay): void
    {
        $this->todayWholeDay = $todayWholeDay;
    }

    public function isTodayWholeDay(): bool
    {
        return $this->todayWholeDay;
    }

    public function getQuietFrom(): ?string
    {
        return $this->quietFrom;
    }

    public function getQuietUntil(): ?string
    {
        return $this->quietUntil;
    }

    /**
     * The quiet hours in the owner's time zone, null when none are set.
     */
    public function getQuietHours(string $timeZone): ?QuietHours
    {
        if (null === $this->quietFrom || null === $this->quietUntil) {
            return null;
        }

        return QuietHours::between($this->quietFrom, $this->quietUntil, $timeZone);
    }

    public function getReturnedAt(): ?CarbonImmutable
    {
        return $this->returnedAt;
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getVehicle(): Vehicle
    {
        return $this->vehicle;
    }

    public function getTerm(): LeaseTerm
    {
        return new LeaseTerm($this->startDate, $this->endDate, $this->endDateConvention);
    }

    public function getAllowance(): Allowance
    {
        return Allowance::ofTotal(Distance::fromMetres($this->allowanceMetres));
    }

    public function getStartOdometer(): Distance
    {
        return Distance::fromMetres($this->startOdometerMetres);
    }

    public function getExcessCostPerKm(): Money
    {
        return Money::ofMinor($this->excessCostPerKmMinor, $this->currency);
    }

    public function getTolerance(): Tolerance
    {
        return Tolerance::fromBasisPoints($this->toleranceBasisPoints);
    }
}
