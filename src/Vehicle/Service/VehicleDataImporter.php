<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Service;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\Pace;
use Polaris\Lease\Domain\RiskLevel;
use Polaris\Lease\Domain\Tolerance;
use Polaris\Lease\Service\Dto\LeaseTerms;
use Polaris\Lease\Service\Dto\ProjectionSnapshotPoint;
use Polaris\Lease\Service\Exception\LeaseTermsRejected;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Lease\Service\ProjectionSnapshotService;
use Polaris\Reading\Domain\ReadingSource;
use Polaris\Reading\Service\Dto\ReadingImport;
use Polaris\Reading\Service\OdometerReadingService;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\DistanceUnit;
use Polaris\Shared\Domain\Money;
use Polaris\Shared\Domain\TimeZone;
use Polaris\Vehicle\Domain\JsonObject;
use Polaris\Vehicle\Entity\Vehicle;
use Polaris\Vehicle\Service\Dto\VehicleDataImportResult;
use Polaris\Vehicle\Service\Enum\LeaseImportOutcome;
use Polaris\Vehicle\Service\Exception\VehicleDataRejected;

/**
 * Reads back a file written by {@see VehicleDataExporter} into a vehicle, merging with what it has: the lease is created
 * only when the vehicle has none, a reading at an instant already stored is left alone, and so is a snapshot of a local day
 * that has one. Nothing is replaced or deleted, so importing the same file twice changes nothing the second time.
 *
 * The whole file is read and checked before anything is written, and the writes are one transaction: a file that does not
 * fit imports nothing. The vehicle is the one the caller chooses; the file does not say which car it is.
 */
final readonly class VehicleDataImporter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LeaseTermsService $leases,
        private OdometerReadingService $readings,
        private ProjectionSnapshotService $snapshots,
    ) {
    }

    /**
     * @throws VehicleDataRejected when the file is not an export, of an unknown version, or has an invalid value
     * @throws ORMException
     */
    public function import(Vehicle $vehicle, string $json): VehicleDataImportResult
    {
        try {
            $file = JsonObject::parse($json);
        } catch (\InvalidArgumentException $e) {
            throw new VehicleDataRejected(VehicleDataRejected::NOT_JSON, $e->getMessage(), $e);
        }

        if (!$file->has('format') || !$file->has('version') || VehicleDataExporter::FORMAT !== $file->string('format')) {
            throw new VehicleDataRejected(VehicleDataRejected::NOT_AN_EXPORT, 'The file is not an export of Polaris.');
        }

        if (VehicleDataExporter::VERSION !== $file->int('version')) {
            throw new VehicleDataRejected(VehicleDataRejected::UNKNOWN_VERSION, \sprintf('The format version %d is not supported (only %d).', $file->int('version'), VehicleDataExporter::VERSION));
        }

        try {
            $lease = $file->nullableObject('lease');
            $terms = null === $lease ? null : $this->terms($lease);
            $returnedAt = null === $lease ? null : $this->nullableInstant($lease, 'returnedAt');

            if (null !== $terms && null !== $returnedAt && $returnedAt->getTimestamp() <= $terms->term->start()->getTimestamp()) {
                throw new \InvalidArgumentException('lease.returnedAt is not after the start of the lease.');
            }
            $readings = $this->readingsOf($file);
            $points = $this->snapshotsOf($file);
        } catch (\InvalidArgumentException $e) {
            throw new VehicleDataRejected(VehicleDataRejected::INVALID, $e->getMessage(), $e);
        }

        try {
            return $this->entityManager->wrapInTransaction(function () use ($vehicle, $terms, $returnedAt, $readings, $points): VehicleDataImportResult {
                $lease = LeaseImportOutcome::None;

                if (null !== $terms) {
                    $lease = $this->leases->import($vehicle, $terms, $returnedAt) ? LeaseImportOutcome::Created : LeaseImportOutcome::Kept;
                }

                return new VehicleDataImportResult(
                    $lease,
                    $this->readings->import($vehicle, $readings),
                    $this->snapshots->import($vehicle, $points),
                );
            });
        } catch (LeaseTermsRejected|\InvalidArgumentException $e) {
            throw new VehicleDataRejected(VehicleDataRejected::INVALID, $e->getMessage(), $e);
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function terms(JsonObject $lease): LeaseTerms
    {
        $convention = EndDateConvention::tryFrom($lease->string('endDateConvention')) ?? throw new \InvalidArgumentException('lease.endDateConvention is not a known convention.');
        $currency = Currency::tryFrom($lease->string('currency')) ?? throw new \InvalidArgumentException('lease.currency is not a currency Polaris supports.');

        return new LeaseTerms(
            LeaseTerm::fromCalendarDates($this->day($lease, 'startDate'), $this->day($lease, 'endDate'), $convention),
            Allowance::ofTotal(Distance::fromMetres($lease->int('allowanceMetres'))),
            Distance::fromMetres($lease->int('startOdometerMetres')),
            Money::ofMinor($lease->int('excessCostPerKmMinor'), $currency),
            Tolerance::fromBasisPoints($lease->int('toleranceBasisPoints')),
        );
    }

    /**
     * The readings, oldest first, checked to be in order: an odometer never goes down.
     *
     * @return list<ReadingImport>
     *
     * @throws \InvalidArgumentException
     */
    private function readingsOf(JsonObject $file): array
    {
        $readings = [];

        foreach ($file->objects('readings') as $reading) {
            $source = ReadingSource::tryFrom($reading->string('source')) ?? throw new \InvalidArgumentException('A reading has an unknown source.');
            $unit = $reading->nullableString('odometerRawUnit');
            $raw = $reading->nullableString('odometerRaw');

            if (ReadingSource::Api === $source ? (null === $raw || null === $unit) : (null !== $raw || null !== $unit)) {
                throw new \InvalidArgumentException('The raw odometer and its unit belong to a reading of the API, and only to it.');
            }

            $readings[] = new ReadingImport(
                $this->instant($reading, 'readAt'),
                Distance::fromMetres($reading->int('odometerMetres')),
                $source,
                $raw,
                null === $unit ? null : (DistanceUnit::tryFrom($unit) ?? throw new \InvalidArgumentException('A reading has an unknown odometer unit.')),
                $this->nullableInstant($reading, 'confirmedAt'),
            );
        }

        usort($readings, static fn (ReadingImport $a, ReadingImport $b): int => $a->readAt <=> $b->readAt);

        $previous = null;

        foreach ($readings as $reading) {
            if (null !== $previous && $reading->odometer->metres() < $previous->odometer->metres()) {
                throw new \InvalidArgumentException(\sprintf('The odometer goes down between the readings of %s and %s.', $previous->readAt->toIso8601String(), $reading->readAt->toIso8601String()));
            }

            $previous = $reading;
        }

        return $readings;
    }

    /**
     * @return list<ProjectionSnapshotPoint>
     *
     * @throws \InvalidArgumentException
     */
    private function snapshotsOf(JsonObject $file): array
    {
        $points = [];

        foreach ($file->objects('snapshots') as $snapshot) {
            $points[] = new ProjectionSnapshotPoint(
                $this->day($snapshot, 'localDay'),
                $this->nullableInstant($snapshot, 'cutoffAt'),
                $this->instant($snapshot, 'computedAt'),
                $this->instant($snapshot, 'asOf'),
                Distance::fromMetres($snapshot->int('drivenMetres')),
                Distance::fromMetres($snapshot->int('allowedToDateMetres')),
                Distance::fromMetres($snapshot->int('projectedAtEndMetres')),
                Distance::fromMetres($snapshot->int('dailyBudgetMetres')),
                RiskLevel::tryFrom($snapshot->string('riskLevel')) ?? throw new \InvalidArgumentException('A snapshot has an unknown risk level.'),
                $this->nullablePace($snapshot, 'overallPaceMetresPerDay'),
                $this->nullablePace($snapshot, 'recentPaceMetresPerDay'),
                $this->nullablePace($snapshot, 'blendedPaceMetresPerDay'),
            );
        }

        return $points;
    }

    private function nullablePace(JsonObject $object, string $key): ?Pace
    {
        $value = $object->nullableFloat($key);

        return null === $value ? null : Pace::fromMetresPerDay($value);
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function day(JsonObject $object, string $key): CarbonImmutable
    {
        $value = $object->string($key);
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, TimeZone::UTC);

        return $day instanceof CarbonImmutable && $day->format('Y-m-d') === $value ? $day : throw new \InvalidArgumentException(\sprintf('%s is not a date (Y-m-d): %s.', $key, $value));
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function instant(JsonObject $object, string $key): CarbonImmutable
    {
        $value = $object->string($key);
        $instant = CarbonImmutable::createFromFormat(\DATE_ATOM, $value);

        return $instant instanceof CarbonImmutable ? $instant->utc() : throw new \InvalidArgumentException(\sprintf('%s is not a date and time (ISO 8601): %s.', $key, $value));
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function nullableInstant(JsonObject $object, string $key): ?CarbonImmutable
    {
        return $object->has($key) ? $this->instant($object, $key) : null;
    }
}
