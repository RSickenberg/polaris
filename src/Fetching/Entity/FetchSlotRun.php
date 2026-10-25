<?php

declare(strict_types=1);

namespace Polaris\Fetching\Entity;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Polaris\Fetching\Domain\SlotRunStatus;
use Polaris\Fetching\Repository\FetchSlotRunRepository;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * One scheduled slot of one vehicle, written before the fetch is queued. The unique (vehicle, slot) pair
 * is what keeps a slot from being queued twice, by a repeated tick or a restarted worker; the status
 * says how the slot ended.
 */
#[ORM\Entity(repositoryClass: FetchSlotRunRepository::class)]
#[ORM\UniqueConstraint(columns: ['vehicle_id', 'slot_at'])]
class FetchSlotRun
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Vehicle $vehicle;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private CarbonImmutable $slotAt;

    #[ORM\Column(length: 16, enumType: SlotRunStatus::class)]
    private SlotRunStatus $status;

    /**
     * How many times the state of the car was checked.
     */
    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private CarbonImmutable $updatedAt;

    public function __construct(Vehicle $vehicle, CarbonImmutable $slotAt, CarbonImmutable $now)
    {
        if (0 !== $slotAt->getOffset()) {
            throw new \InvalidArgumentException(\sprintf('A fetch slot must be a UTC instant, got %s.', $slotAt->toIso8601String()));
        }

        $this->id = new Ulid();
        $this->vehicle = $vehicle;
        $this->slotAt = $slotAt;
        $this->status = SlotRunStatus::Dispatched;
        $this->updatedAt = $now;
    }

    public function getVehicle(): Vehicle
    {
        return $this->vehicle;
    }

    public function getSlotAt(): CarbonImmutable
    {
        return $this->slotAt;
    }

    public function getStatus(): SlotRunStatus
    {
        return $this->status;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getUpdatedAt(): CarbonImmutable
    {
        return $this->updatedAt;
    }

    public function record(SlotRunStatus $status, int $attempts, CarbonImmutable $now): void
    {
        $this->status = $status;
        $this->attempts = $attempts;
        $this->updatedAt = $now;
    }
}
