<?php

declare(strict_types=1);

namespace Polaris\Reading\Service;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Reading\Domain\ReadingOrder;
use Polaris\Reading\Domain\ReadingRejection;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Reading\Event\ReadingConfirmed;
use Polaris\Reading\Event\ReadingStored;
use Polaris\Reading\Form\Model\OdometerReadingData;
use Polaris\Reading\Repository\OdometerReadingRepository;
use Polaris\Reading\Service\Dto\ApiRecording;
use Polaris\Reading\Service\Exception\ReadingRejected;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\DistanceUnit;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Records odometer readings (typed by the user or read from the car) and lists the history. The only place the rules live:
 * the form controller and the console command both call it.
 */
final readonly class OdometerReadingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OdometerReadingRepository $readings,
        private ReadingOrder $order,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * @param OdometerReadingData $data validated first (see the constraints on the class)
     *
     * @throws \InvalidArgumentException when the data has not been validated first
     * @throws ReadingRejected           when the reading is in the future, duplicates an instant or breaks the order of the readings
     * @throws ORMException
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

            throw new ReadingRejected($rejection, ['limit' => $neighbour->getOdometer()->in($data->distanceUnit), 'unit' => $data->distanceUnit->value, 'at' => $neighbour->getReadAt()->getTimestamp()]);
        }

        try {
            $this->entityManager->persist($reading);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Another request stored a reading at the same instant after the check above.
            throw new ReadingRejected(ReadingRejection::DuplicateInstant);
        }

        $this->events->dispatch(new ReadingStored($vehicle));

        return $reading;
    }

    /**
     * Records an odometer value read from the car, only when it changed: a value equal to the previous
     * reading (or a reading already stored at the same instant) is not stored; an equal one is noted on the latest
     * reading as {@see OdometerReading::confirm()}. A value that breaks the
     * order of the readings is refused without an exception, so a scheduled fetch is not retried.
     *
     * @throws ORMException
     */
    public function recordFromApi(Vehicle $vehicle, CarbonImmutable $readAt, Distance $odometer): ApiRecording
    {
        $readAt = $readAt->utc();

        if (null !== $this->readings->findAt($vehicle, $readAt)) {
            return ApiRecording::unchanged();
        }

        // Built first: the stored value is the one derived from the raw miles, which is what the next read is compared with.
        $reading = OdometerReading::fromTesla(
            $vehicle,
            $readAt,
            number_format($odometer->in(DistanceUnit::Mile), 3, '.', ''),
            DistanceUnit::Mile,
        );
        $previous = $this->readings->findBefore($vehicle, $readAt);

        $next = $this->readings->findAfter($vehicle, $readAt);

        if (null !== $previous && $previous->getOdometer()->equals($reading->getOdometer())) {
            // The car has not moved: it is noted on the reading, so that the projection goes on from now. Only for the
            // latest reading (a later one would contradict it), and never later than the clock.
            if (null === $next) {
                $previous->confirm($readAt->min(CarbonImmutable::instance($this->clock->now())->utc()));
                $this->entityManager->flush();
                $this->events->dispatch(new ReadingConfirmed($vehicle));
            }

            return ApiRecording::unchanged();
        }

        $rejection = $this->order->check($reading->toSample(), $previous?->toSample(), $next?->toSample());
        if (null !== $rejection) {
            $neighbour = ReadingRejection::LowerThanPrevious === $rejection ? $previous : $next;
            \assert(null !== $neighbour);

            return ApiRecording::rejected($rejection, $neighbour);
        }

        try {
            $this->entityManager->persist($reading);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Another writer stored a reading at the same instant after the check above.
            return ApiRecording::unchanged();
        }

        $this->events->dispatch(new ReadingStored($vehicle));

        return ApiRecording::stored($reading);
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
