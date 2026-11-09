<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Service;

use Carbon\CarbonImmutable;
use Polaris\Lease\Service\Dto\ProjectionSnapshotPoint;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Lease\Service\ProjectionHistoryService;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Reading\Service\OdometerReadingService;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Writes what Polaris keeps about a vehicle into one JSON file, to keep it or to bring it to another install: the
 * lease, the odometer readings and the daily projection snapshots. It leaves out what identifies the car or the account
 * (the VIN, the Tesla tokens) and the raw answers of the car (`vehicle_data`), which can carry a location. The file has
 * a format name and a version, so that a later Polaris can still read it; {@see VehicleDataImporter} reads it back.
 *
 * Distances are integer metres, instants are ISO 8601 in UTC, local days are `Y-m-d`, enums are their values.
 */
final readonly class VehicleDataExporter
{
    public const string FORMAT = 'polaris.vehicle-data';
    public const int VERSION = 1;

    public function __construct(
        private LeaseTermsService $leases,
        private OdometerReadingService $readings,
        private ProjectionHistoryService $snapshots,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws \JsonException
     */
    public function export(Vehicle $vehicle): string
    {
        return json_encode([
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exportedAt' => CarbonImmutable::instance($this->clock->now())->utc()->toIso8601String(),
            'lease' => $this->lease($vehicle),
            'readings' => $this->readingsOf($vehicle),
            'snapshots' => $this->snapshotsOf($vehicle),
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    /**
     * The name of the file to download: the vehicle's name and the day.
     */
    public function fileName(Vehicle $vehicle): string
    {
        $name = new AsciiSlugger()->slug($vehicle->getName())->lower()->toString();

        $day = CarbonImmutable::instance($this->clock->now())->utc()->format('Y-m-d');

        return \sprintf('polaris-%s-%s.json', '' === $name ? 'vehicle' : $name, $day);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lease(Vehicle $vehicle): ?array
    {
        $contract = $this->leases->find($vehicle);

        if (null === $contract) {
            return null;
        }

        $term = $contract->getTerm();

        return [
            'startDate' => $term->start()->format('Y-m-d'),
            'endDate' => $term->end()->format('Y-m-d'),
            'endDateConvention' => $term->endDateConvention()->value,
            'allowanceMetres' => $contract->getAllowance()->total()->metres(),
            'startOdometerMetres' => $contract->getStartOdometer()->metres(),
            'excessCostPerKmMinor' => $contract->getExcessCostPerKm()->minorAmount(),
            'currency' => $contract->getExcessCostPerKm()->currency()->value,
            'toleranceBasisPoints' => $contract->getTolerance()->basisPoints(),
            'returnedAt' => $contract->getReturnedAt()?->utc()->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>> oldest first
     */
    private function readingsOf(Vehicle $vehicle): array
    {
        $readings = [];

        foreach (array_reverse($this->readings->history($vehicle)) as $reading) {
            $readings[] = $this->reading($reading);
        }

        return $readings;
    }

    /**
     * @return array<string, mixed>
     */
    private function reading(OdometerReading $reading): array
    {
        return [
            'readAt' => $reading->getReadAt()->utc()->toIso8601String(),
            'odometerMetres' => $reading->getOdometer()->metres(),
            'source' => $reading->getSource()->value,
            'odometerRaw' => $reading->getOdometerRaw(),
            'odometerRawUnit' => $reading->getOdometerRawUnit()?->value,
            'lastVerifiedAt' => $reading->getLastVerifiedAt()?->utc()->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>> oldest local day first
     */
    private function snapshotsOf(Vehicle $vehicle): array
    {
        $snapshots = [];

        foreach ($this->snapshots->history($vehicle) as $point) {
            $snapshots[] = $this->snapshot($point);
        }

        return $snapshots;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ProjectionSnapshotPoint $point): array
    {
        return [
            'localDay' => $point->localDay->format('Y-m-d'),
            'cutoffAt' => $point->cutoffAt?->utc()->toIso8601String(),
            'computedAt' => $point->computedAt->utc()->toIso8601String(),
            'asOf' => $point->asOf->utc()->toIso8601String(),
            'drivenMetres' => $point->driven->metres(),
            'allowedToDateMetres' => $point->allowedToDate->metres(),
            'projectedAtEndMetres' => $point->projectedAtEnd->metres(),
            'dailyBudgetMetres' => $point->dailyBudget->metres(),
            'riskLevel' => $point->riskLevel->value,
            'overallPaceMetresPerDay' => $point->overallPace?->metresPerDay(),
            'recentPaceMetresPerDay' => $point->recentPace?->metresPerDay(),
            'blendedPaceMetresPerDay' => $point->blendedPace?->metresPerDay(),
        ];
    }
}
