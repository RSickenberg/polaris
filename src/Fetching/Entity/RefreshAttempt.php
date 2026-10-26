<?php

declare(strict_types=1);

namespace Polaris\Fetching\Entity;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Polaris\Fetching\Domain\RefreshOutcome;
use Polaris\Fetching\Repository\RefreshAttemptRepository;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * One accepted manual refresh of one vehicle, written before the fetch is queued. Its id travels in the
 * message, so the page that asked can find how the fetch ended.
 */
#[ORM\Entity(repositoryClass: RefreshAttemptRepository::class)]
class RefreshAttempt
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Vehicle $vehicle;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private CarbonImmutable $requestedAt;

    #[ORM\Column(length: 16, enumType: RefreshOutcome::class)]
    private RefreshOutcome $outcome;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private CarbonImmutable $updatedAt;

    public function __construct(Vehicle $vehicle, CarbonImmutable $now)
    {
        $this->id = new Ulid();
        $this->vehicle = $vehicle;
        $this->requestedAt = $now;
        $this->outcome = RefreshOutcome::Queued;
        $this->updatedAt = $now;
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getVehicle(): Vehicle
    {
        return $this->vehicle;
    }

    public function getRequestedAt(): CarbonImmutable
    {
        return $this->requestedAt;
    }

    public function getOutcome(): RefreshOutcome
    {
        return $this->outcome;
    }

    public function getUpdatedAt(): CarbonImmutable
    {
        return $this->updatedAt;
    }

    public function settle(RefreshOutcome $outcome, CarbonImmutable $now): void
    {
        $this->outcome = $outcome;
        $this->updatedAt = $now;
    }
}
