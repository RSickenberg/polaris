<?php

declare(strict_types=1);

namespace Polaris\Alert\Entity;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Polaris\Alert\Repository\AlertLogRepository;
use Polaris\Lease\Domain\RiskLevel;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * One risk level of one vehicle, as the Alert module last saw it. The newest row of a vehicle is the known level:
 * a new assessment is compared with it, so the same level never alerts twice. A row is written whatever the
 * extension does; the channel says how the alert went out and is null when nothing was sent.
 */
#[ORM\Entity(repositoryClass: AlertLogRepository::class)]
#[ORM\Index(columns: ['vehicle_id', 'sent_at'])]
class AlertLog
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Vehicle $vehicle;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private CarbonImmutable $sentAt;

    #[ORM\Column(length: 16, enumType: AlertType::class)]
    private AlertType $type;

    #[ORM\Column(length: 8, enumType: RiskLevel::class)]
    private RiskLevel $riskLevel;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $channel;

    public function __construct(Vehicle $vehicle, CarbonImmutable $sentAt, AlertType $type, RiskLevel $riskLevel, ?string $channel = null)
    {
        if (0 !== $sentAt->getOffset()) {
            throw new \InvalidArgumentException(\sprintf('An alert must be logged at a UTC instant, got %s.', $sentAt->toIso8601String()));
        }

        $this->id = new Ulid();
        $this->vehicle = $vehicle;
        $this->sentAt = $sentAt;
        $this->type = $type;
        $this->riskLevel = $riskLevel;
        $this->channel = $channel;
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getVehicle(): Vehicle
    {
        return $this->vehicle;
    }

    public function getSentAt(): CarbonImmutable
    {
        return $this->sentAt;
    }

    public function getType(): AlertType
    {
        return $this->type;
    }

    public function getRiskLevel(): RiskLevel
    {
        return $this->riskLevel;
    }

    public function getChannel(): ?string
    {
        return $this->channel;
    }
}
