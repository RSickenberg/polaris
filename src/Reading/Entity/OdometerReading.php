<?php

declare(strict_types=1);

namespace Polaris\Reading\Entity;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Polaris\Reading\Domain\ReadingSource;
use Polaris\Reading\Repository\OdometerReadingRepository;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\DistanceUnit;
use Polaris\Shared\Domain\OdometerSample;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * The odometer of a vehicle at an instant.
 *
 * The distance is stored in integer metres. A reading that comes from the Tesla API also
 * keeps the raw value and its unit, so the original is never lost.
 */
#[ORM\Entity(repositoryClass: OdometerReadingRepository::class)]
#[ORM\UniqueConstraint(columns: ['vehicle_id', 'read_at'])]
class OdometerReading
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Vehicle $vehicle;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private CarbonImmutable $readAt;

    /**
     * The odometer exactly as the Tesla API returned it, only for {@see ReadingSource::Api}.
     * Its unit is {@see $odometerRawUnit}: do not assume miles.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3, nullable: true)]
    private ?string $odometerRaw;

    #[ORM\Column(length: 2, nullable: true, enumType: DistanceUnit::class)]
    private ?DistanceUnit $odometerRawUnit;

    #[ORM\Column]
    private int $odometerM;

    #[ORM\Column(length: 16, enumType: ReadingSource::class)]
    private ReadingSource $source;

    private function __construct(Vehicle $vehicle, CarbonImmutable $readAt, Distance $odometer, ?string $odometerRaw, ?DistanceUnit $odometerRawUnit, ReadingSource $source)
    {
        if (0 !== $readAt->getOffset()) {
            throw new \InvalidArgumentException(\sprintf('An odometer reading must be read at a UTC instant, got %s.', $readAt->toIso8601String()));
        }

        $this->id = new Ulid();
        $this->vehicle = $vehicle;
        $this->readAt = $readAt;
        $this->odometerM = $odometer->metres();
        $this->odometerRaw = $odometerRaw;
        $this->odometerRawUnit = $odometerRawUnit;
        $this->source = $source;
    }

    public static function manual(Vehicle $vehicle, CarbonImmutable $readAt, Distance $odometer): self
    {
        return new self($vehicle, $readAt, $odometer, null, null, ReadingSource::Manual);
    }

    /**
     * @param string       $raw  the odometer as the Tesla API returned it, for example "12345.678"
     * @param DistanceUnit $unit the unit of $raw
     */
    public static function fromTesla(Vehicle $vehicle, CarbonImmutable $readAt, string $raw, DistanceUnit $unit): self
    {
        if (1 !== preg_match('/^\d{1,9}(\.\d{1,3})?$/', $raw)) {
            throw new \InvalidArgumentException(\sprintf('A Tesla odometer must be a non-negative number with at most 3 decimals, got "%s".', $raw));
        }

        return new self($vehicle, $readAt, Distance::from((float) $raw, $unit), $raw, $unit, ReadingSource::Api);
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getVehicle(): Vehicle
    {
        return $this->vehicle;
    }

    public function getReadAt(): CarbonImmutable
    {
        return $this->readAt;
    }

    public function getOdometer(): Distance
    {
        return Distance::fromMetres($this->odometerM);
    }

    public function getOdometerRaw(): ?string
    {
        return $this->odometerRaw;
    }

    public function getOdometerRawUnit(): ?DistanceUnit
    {
        return $this->odometerRawUnit;
    }

    public function getSource(): ReadingSource
    {
        return $this->source;
    }

    public function toSample(): OdometerSample
    {
        return new OdometerSample($this->readAt, $this->getOdometer());
    }
}
