<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Polaris\Vehicle\Domain\Vin;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
class Vehicle
{
    public const int NAME_MAX_LENGTH = 100;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\Column(length: 17, unique: true)]
    private string $vin;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $name;

    public function __construct(Vin $vin, string $name)
    {
        $this->id = new Ulid();
        $this->vin = $vin->value();
        $this->rename($name);
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getVin(): Vin
    {
        return new Vin($this->vin);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $name = trim($name);

        if ('' === $name || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A vehicle name must have 1 to %d characters.', self::NAME_MAX_LENGTH));
        }

        $this->name = $name;
    }
}
