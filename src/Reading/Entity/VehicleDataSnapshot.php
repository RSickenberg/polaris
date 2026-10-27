<?php

declare(strict_types=1);

namespace Polaris\Reading\Entity;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Polaris\Reading\Repository\VehicleDataSnapshotRepository;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * What a live `vehicle_data` call answered, kept as it came (minus the location, which is never stored) for
 * later use: figures the app does not show yet can be read back without asking the car again. One row per
 * instant the car reported, whether or not the odometer changed.
 */
#[ORM\Entity(repositoryClass: VehicleDataSnapshotRepository::class)]
#[ORM\UniqueConstraint(columns: ['vehicle_id', 'captured_at'])]
class VehicleDataSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Vehicle $vehicle;

    /**
     * The time the car reported (`vehicle_state.timestamp`), UTC.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private CarbonImmutable $capturedAt;

    /**
     * When Polaris received it, UTC.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private CarbonImmutable $receivedAt;

    /**
     * The `response` object of the answer, without location keys.
     *
     * @var array<array-key, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $payload;

    /**
     * @param array<array-key, mixed> $payload
     */
    public function __construct(Vehicle $vehicle, CarbonImmutable $capturedAt, CarbonImmutable $receivedAt, array $payload)
    {
        $this->id = new Ulid();
        $this->vehicle = $vehicle;
        $this->capturedAt = $capturedAt->utc();
        $this->receivedAt = $receivedAt->utc();
        $this->payload = $payload;
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getVehicle(): Vehicle
    {
        return $this->vehicle;
    }

    public function getCapturedAt(): CarbonImmutable
    {
        return $this->capturedAt;
    }

    public function getReceivedAt(): CarbonImmutable
    {
        return $this->receivedAt;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
