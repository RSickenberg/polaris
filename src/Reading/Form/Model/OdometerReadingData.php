<?php

declare(strict_types=1);

namespace Polaris\Reading\Form\Model;

use Polaris\Shared\Domain\DistanceUnit;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A manual odometer reading as the user enters it: the value in the unit they chose and
 * the instant it was read. Shared by the form and the console command, so both validate
 * the same way. OdometerReadingService converts it to integer metres and UTC.
 *
 * Not readonly: the Form component writes the submitted values into it.
 */
final class OdometerReadingData
{
    /** The instant the odometer was read. */
    #[Assert\NotNull]
    public ?\DateTimeImmutable $readAt = null;

    public DistanceUnit $distanceUnit = DistanceUnit::Kilometre;

    /** In {@see $distanceUnit}. */
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    #[Assert\LessThan(value: 100_000_000, message: 'This odometer value is too large.')]
    public ?int $odometer = null;
}
