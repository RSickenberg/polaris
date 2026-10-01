<?php

declare(strict_types=1);

namespace Polaris\Reading\Service;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Polaris\Reading\Domain\ReadingOrder;
use Polaris\Reading\Domain\ReadingRejection;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Reading\Form\Model\OdometerReadingData;
use Polaris\Reading\Repository\OdometerReadingRepository;
use Polaris\Shared\Domain\Distance;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;

/**
 * Records manual odometer readings and lists the history. The only place the rules live:
 * the form controller and the console command both call it.
 */
final readonly class OdometerReadingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OdometerReadingRepository $readings,
        private ReadingOrder $order,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param OdometerReadingData $data validated first (see the constraints on the class)
     *
     * @throws \InvalidArgumentException when the data has not been validated first
     * @throws ReadingRejected           when the reading is in the future, duplicates an instant or breaks the order of the readings
     */
    public function recordManual(Vehicle $vehicle, OdometerReadingData $data): OdometerReading
    {
        if (null === $data->readAt || null === $data->odometer) {
            throw new \InvalidArgumentException('The odometer reading data is incomplete: validate it before recording it.');
        }

        $readAt = CarbonImmutable::instance($data->readAt)->utc();
        $odometer = Distance::from($data->odometer, $data->distanceUnit);

        if ($readAt->greaterThan(CarbonImmutable::instance($this->clock->now()))) {
            throw new ReadingRejected(ReadingRejection::InFuture);
        }

        if (null !== $this->readings->findAt($vehicle, $readAt)) {
            throw new ReadingRejected(ReadingRejection::DuplicateInstant);
        }

        $previous = $this->readings->findBefore($vehicle, $readAt);
        $next = $this->readings->findAfter($vehicle, $readAt);
        $reading = OdometerReading::manual($vehicle, $readAt, $odometer);

        $rejection = $this->order->check($reading->toSample(), $previous?->toSample(), $next?->toSample());
        if (null !== $rejection) {
            $neighbour = ReadingRejection::LowerThanPrevious === $rejection ? $previous : $next;
            \assert(null !== $neighbour);

            throw new ReadingRejected($rejection, ['%limit%' => number_format($neighbour->getOdometer()->in($data->distanceUnit), 0, '.', ' '), '%unit%' => $data->distanceUnit->value, '%at%' => $neighbour->getReadAt()->toIso8601ZuluString('minute')]);
        }

        try {
            $this->entityManager->persist($reading);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Another request stored a reading at the same instant after the check above.
            throw new ReadingRejected(ReadingRejection::DuplicateInstant);
        }

        return $reading;
    }

    /**
     * The readings of the vehicle, newest first.
     *
     * @return list<OdometerReading>
     */
    public function history(Vehicle $vehicle): array
    {
        return $this->readings->findHistory($vehicle);
    }
}
