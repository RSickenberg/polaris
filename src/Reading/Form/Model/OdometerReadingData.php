<?php

declare(strict_types=1);

namespace Polaris\Reading\Form\Model;

use Polaris\Shared\Domain\DistanceUnit;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A manual odometer reading as the user enters it: the value in the unit they chose and
 * the instant it was read. Shared by the form and the console command, so both validate
 * the same way. OdometerReadingService converts it to integer metres and UTC.
 *
 * Not readonly: the Form component writes the submitted values into it.
 */
final class OdometerReadingData
{
    /** 1,000,000 km: far beyond any real odometer, and well inside the 32-bit odometer_m column. */
    public const int MAX_METRES = 1_000_000_000;

    /** The instant the odometer was read. */
    #[Assert\NotNull]
    public ?\DateTimeImmutable $readAt = null;

    public DistanceUnit $distanceUnit = DistanceUnit::Kilometre;

    /** In {@see $distanceUnit}. */
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    public ?int $odometer = null;

    #[Assert\Callback]
    public function validateOdometerRange(ExecutionContextInterface $context): void
    {
        if (null === $this->odometer || $this->odometer < 0) {
            return;
        }

        if ($this->odometer * $this->distanceUnit->metres() > self::MAX_METRES) {
            $context->buildViolation('reading.odometer.too_large')
                ->atPath('odometer')
                ->addViolation();
        }
    }
}
