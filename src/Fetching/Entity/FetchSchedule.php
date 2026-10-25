<?php

declare(strict_types=1);

namespace Polaris\Fetching\Entity;

use Doctrine\ORM\Mapping as ORM;
use Polaris\Fetching\Domain\LocalTime;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * The local times at which the odometer of a vehicle is read: one or two a day, in the time zone of the
 * owner. A vehicle without a row reads once a day at {@see LocalTime::default()}.
 */
#[ORM\Entity]
class FetchSchedule
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private Vehicle $vehicle;

    #[ORM\Column(length: 5)]
    private string $firstSlot;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $secondSlot;

    public function __construct(Vehicle $vehicle, LocalTime $first, ?LocalTime $second = null)
    {
        $this->id = new Ulid();
        $this->vehicle = $vehicle;
        $this->change($first, $second);
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getVehicle(): Vehicle
    {
        return $this->vehicle;
    }

    /**
     * @return non-empty-list<LocalTime>
     */
    public function getSlots(): array
    {
        $slots = [LocalTime::fromString($this->firstSlot)];

        if (null !== $this->secondSlot) {
            $slots[] = LocalTime::fromString($this->secondSlot);
        }

        return $slots;
    }

    /**
     * @throws \InvalidArgumentException when both slots are the same time
     */
    public function change(LocalTime $first, ?LocalTime $second = null): void
    {
        if (null !== $second && $first->equals($second)) {
            throw new \InvalidArgumentException(\sprintf('The two fetch slots must differ, got %s twice.', $first));
        }

        $this->firstSlot = (string) $first;
        $this->secondSlot = null === $second ? null : (string) $second;
    }
}
