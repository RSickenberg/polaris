<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Vehicle;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Polaris\Lease\Form\Model\LeaseContractData;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Lease\Service\ProjectionHistoryService;
use Polaris\Lease\Service\ProjectionSnapshotService;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Reading\Service\OdometerReadingService;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\DistanceUnit;
use Polaris\Shared\Domain\OwnerMidnight;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Domain\JsonObject;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Polaris\Vehicle\Service\Dto\VehicleDataImportResult;
use Polaris\Vehicle\Service\Enum\LeaseImportOutcome;
use Polaris\Vehicle\Service\Exception\VehicleDataRejected;
use Polaris\Vehicle\Service\VehicleDataExporter;
use Polaris\Vehicle\Service\VehicleDataImporter;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * @phpstan-type Override array{
 *     lease?: array<string, mixed>|null,
 *     readings?: list<array<string, mixed>>,
 *     snapshots?: list<array<string, mixed>>,
 * }
 * @phpstan-type ExportFile array{
 *     format: string,
 *     version: int,
 *     exportedAt: string,
 *     lease: array<string, mixed>,
 *     readings: list<array<string, mixed>>,
 *     snapshots: non-empty-list<array<string, mixed>>,
 * }
 */
#[CoversClass(VehicleDataExporter::class)]
#[CoversClass(VehicleDataImporter::class)]
#[CoversClass(JsonObject::class)]
final class VehicleDataTransferTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SignsIn;

    private const string SOURCE_VIN = '5YJ3E7EB2NF000501';
    private const string FIRST_READING_AT = '2026-06-01T10:00:00+00:00';
    private const string SECOND_READING_AT = '2026-07-01T12:00:00+00:00';

    private EntityManagerInterface $entityManager;
    private LeaseTermsService $leases;
    private VehicleDataExporter $exporter;
    private VehicleDataImporter $importer;
    private Vehicle $source;
    private Vehicle $target;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-07-03 00:05:00 UTC');
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->leases = $container->get(LeaseTermsService::class);
        $this->exporter = $container->get(VehicleDataExporter::class);
        $this->importer = $container->get(VehicleDataImporter::class);

        // The source: a lease, a reading typed by hand, one from the API (in miles) confirmed later, a snapshot.
        $this->source = $this->vehicle(self::SOURCE_VIN, 'Source');
        $this->leases->save($this->source, self::leaseData(15_000));
        $this->manualReading($this->source, self::FIRST_READING_AT, 10_000);
        $api = OdometerReading::fromTesla(
            $this->source,
            CarbonImmutable::parse(self::SECOND_READING_AT),
            '7000.000',
            DistanceUnit::Mile,
        );
        $api->verify(CarbonImmutable::parse('2026-07-02T06:00:00+00:00'));
        $this->entityManager->persist($api);
        $this->entityManager->flush();
        $container->get(ProjectionSnapshotService::class)->save(
            $this->source,
            OwnerMidnight::opening(CarbonImmutable::parse('2026-07-03'), 'UTC'),
        );

        $this->target = $this->vehicle('5YJ3E7EB2NF000502', 'Target');
    }

    public function testTheExportHoldsTheLeaseTheReadingsAndTheSnapshotsButNotTheVin(): void
    {
        $json = $this->exporter->export($this->source);

        self::assertStringNotContainsString(self::SOURCE_VIN, $json);
        /** @var ExportFile $file */
        $file = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        self::assertSame(VehicleDataExporter::FORMAT, $file['format']);
        self::assertSame(VehicleDataExporter::VERSION, $file['version']);
        self::assertSame('2026-07-03T00:05:00+00:00', $file['exportedAt']);
        self::assertSame([
            'startDate' => '2026-01-01',
            'endDate' => '2027-01-01',
            'endDateConvention' => 'exclusive',
            'allowanceMetres' => 15_000_000,
            'startOdometerMetres' => 0,
            'excessCostPerKmMinor' => 12,
            'currency' => 'CHF',
            'toleranceBasisPoints' => 0,
            'returnedAt' => null,
        ], $file['lease']);
        self::assertSame(['manual', 'api'], array_column($file['readings'], 'source'), 'Oldest first.');
        self::assertSame(
            [self::FIRST_READING_AT, self::SECOND_READING_AT],
            array_column($file['readings'], 'readAt'),
        );
        self::assertSame('7000.000', $file['readings'][1]['odometerRaw']);
        self::assertSame('mi', $file['readings'][1]['odometerRawUnit']);
        self::assertSame('2026-07-02T06:00:00+00:00', $file['readings'][1]['lastVerifiedAt']);
        self::assertCount(1, $file['snapshots']);
        self::assertSame('2026-07-03', $file['snapshots'][0]['localDay']);
    }

    public function testTheFileIsNamedAfterTheVehicleAndTheDay(): void
    {
        self::assertSame('polaris-source-2026-07-03.json', $this->exporter->fileName($this->source));
    }

    public function testAnExportImportedIntoAnEmptyVehicleGivesTheSameData(): void
    {
        $result = $this->importer->import($this->target, $this->exporter->export($this->source));

        self::assertSame(LeaseImportOutcome::Created, $result->lease);
        self::assertSame([2, 0, 0], $this->readingCounts($result));
        self::assertSame([1, 0, 0], $this->snapshotCounts($result));
        self::assertSame($this->exporter->export($this->source), $this->exporter->export($this->target));
    }

    public function testImportingTheSameFileTwiceChangesNothing(): void
    {
        $json = $this->exporter->export($this->source);
        $this->importer->import($this->target, $json);
        $before = $this->exporter->export($this->target);

        $again = $this->importer->import($this->target, $json);

        self::assertSame(LeaseImportOutcome::Kept, $again->lease);
        self::assertSame([0, 2, 0], $this->readingCounts($again));
        self::assertSame([0, 1, 0], $this->snapshotCounts($again));
        self::assertSame($before, $this->exporter->export($this->target));
    }

    public function testAnExistingLeaseIsKeptAndMissingReadingsAreAdded(): void
    {
        $this->leases->save($this->target, self::leaseData(30_000));
        $this->manualReading($this->target, self::FIRST_READING_AT, 10_000);

        $result = $this->importer->import($this->target, $this->exporter->export($this->source));

        self::assertSame(LeaseImportOutcome::Kept, $result->lease);
        self::assertSame([1, 1, 0], $this->readingCounts($result));
        self::assertSame(
            30_000_000,
            $this->leases->find($this->target)?->getAllowance()->total()->metres(),
            'The lease of the vehicle is not replaced.',
        );
        self::assertCount(2, self::getContainer()->get(OdometerReadingService::class)->history($this->target));
    }

    public function testAReadingThatBreaksTheOrderOrIsInTheFutureIsRefusedAndTheRestIsKept(): void
    {
        // The target went further than the file says it was on 1 July.
        $this->manualReading($this->target, '2026-06-20T10:00:00+00:00', 20_000);
        $file = $this->file(['readings' => [
            ['readAt' => self::FIRST_READING_AT, 'odometerMetres' => 10_000_000],
            ['readAt' => self::SECOND_READING_AT, 'odometerMetres' => 11_000_000],
            ['readAt' => '2026-12-01T12:00:00+00:00', 'odometerMetres' => 30_000_000],
        ]]);

        $result = $this->importer->import($this->target, $file);

        self::assertSame(
            [1, 0, 2],
            $this->readingCounts($result),
            'The one before fits, the one after is lower, the last is in the future.',
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badFiles(): iterable
    {
        $export = '{"format": "polaris.vehicle-data", "version": %d, "lease": null, %s"snapshots": []}';

        yield 'not JSON' => ['{"format": ', VehicleDataRejected::NOT_JSON];
        yield 'a list' => ['[1, 2]', VehicleDataRejected::NOT_JSON];
        yield 'an empty object' => ['{}', VehicleDataRejected::NOT_AN_EXPORT];
        yield 'another format' => ['{"format": "something.else", "version": 1}', VehicleDataRejected::NOT_AN_EXPORT];
        yield 'a version from the future' => [
            \sprintf($export, 2, '"readings": [], '),
            VehicleDataRejected::UNKNOWN_VERSION,
        ];
        yield 'readings missing' => [\sprintf($export, 1, ''), VehicleDataRejected::INVALID];
    }

    #[DataProvider('badFiles')]
    public function testAFileThatIsNotAnExportOfThisVersionIsRefusedWholeAndNothingIsWritten(
        string $json,
        string $reason,
    ): void {
        try {
            $this->importer->import($this->target, $json);
            self::fail('The file is refused.');
        } catch (VehicleDataRejected $rejected) {
            self::assertSame($reason, $rejected->reason);
        }

        $this->assertNothingWritten();
    }

    /**
     * @return iterable<string, array{Override}>
     */
    public static function invalidValues(): iterable
    {
        $first = '2026-06-01T10:00:00+00:00';
        $second = '2026-06-02T10:00:00+00:00';

        yield 'a date that is not a date' => [
            ['readings' => [['readAt' => 'yesterday', 'odometerMetres' => 1]]],
        ];
        yield 'an odometer that is text' => [
            ['readings' => [['readAt' => $first, 'odometerMetres' => 'many']]],
        ];
        yield 'an odometer that goes down' => [
            ['readings' => [
                ['readAt' => $first, 'odometerMetres' => 5_000],
                ['readAt' => $second, 'odometerMetres' => 4_000],
            ]],
        ];
        yield 'an unknown source' => [
            ['readings' => [['readAt' => $first, 'odometerMetres' => 5_000, 'source' => 'psychic']]],
        ];
        yield 'a raw odometer on a typed reading' => [
            ['readings' => [
                ['readAt' => $first, 'odometerMetres' => 5_000, 'odometerRaw' => '3.1', 'odometerRawUnit' => 'mi'],
            ]],
        ];
        yield 'a currency that is not supported' => [['lease' => ['currency' => 'XXX']]];
        yield 'a term that is not whole months' => [['lease' => ['endDate' => '2026-12-15']]];
        yield 'an unknown risk level' => [['snapshots' => [['riskLevel' => 'doom']]]];
        yield 'a return before the start' => [['lease' => ['returnedAt' => '2025-12-01T00:00:00+00:00']]];
    }

    /**
     * @param Override $override
     */
    #[DataProvider('invalidValues')]
    public function testAnInvalidValueRefusesTheWholeFileAndNothingIsWritten(array $override): void
    {
        $this->expectException(VehicleDataRejected::class);

        try {
            $this->importer->import($this->target, $this->file($override));
        } finally {
            $this->assertNothingWritten();
        }
    }

    public function testASnapshotSavedBeforeTheCutoffAndThePaceWereStoredIsLeftOut(): void
    {
        $file = $this->file(['snapshots' => [[
            'localDay' => '2026-06-30',
            'cutoffAt' => null,
            'computedAt' => '2026-06-30T23:50:00+00:00',
            'asOf' => '2026-06-30T12:00:00+00:00',
            'drivenMetres' => 1,
            'allowedToDateMetres' => 1,
            'projectedAtEndMetres' => 1,
            'dailyBudgetMetres' => 1,
            'riskLevel' => 'ok',
            'overallPaceMetresPerDay' => null,
            'recentPaceMetresPerDay' => null,
            'blendedPaceMetresPerDay' => null,
        ]]]);

        $result = $this->importer->import($this->target, $file);

        self::assertSame([0, 0, 1], $this->snapshotCounts($result));
        self::assertSame([], self::getContainer()->get(ProjectionHistoryService::class)->history($this->target));
    }

    public function testSnapshotsWithoutALeaseHaveNothingToAttachToAndAreLeftOut(): void
    {
        $result = $this->importer->import($this->target, $this->file(['lease' => null]));

        self::assertSame(LeaseImportOutcome::None, $result->lease);
        self::assertSame(1, $result->snapshots->skipped);
    }

    /**
     * The export of the source with its lease, readings or snapshots replaced. A lease or a snapshot can be given
     * partially: the fields it lacks are those of the lease or of the first snapshot of the export. A reading lacks
     * its source and its raw odometer: it is a manual one.
     *
     * @param Override $override
     */
    private function file(array $override): string
    {
        /** @var ExportFile $file */
        $file = json_decode($this->exporter->export($this->source), true, 16, \JSON_THROW_ON_ERROR);
        $replaced = $file;

        if (\array_key_exists('lease', $override)) {
            $replaced['lease'] = null === $override['lease'] ? null : $override['lease'] + $file['lease'];
        }

        if (isset($override['readings'])) {
            $replaced['readings'] = [];

            foreach ($override['readings'] as $reading) {
                $replaced['readings'][] = $reading + [
                    'source' => 'manual',
                    'odometerRaw' => null,
                    'odometerRawUnit' => null,
                    'lastVerifiedAt' => null,
                ];
            }
        }

        if (isset($override['snapshots'])) {
            $replaced['snapshots'] = [];

            foreach ($override['snapshots'] as $snapshot) {
                $replaced['snapshots'][] = $snapshot + $file['snapshots'][0];
            }
        }

        return json_encode($replaced, \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{int, int, int} added, already there, refused
     */
    private function readingCounts(VehicleDataImportResult $result): array
    {
        return [$result->readings->added, $result->readings->alreadyThere, $result->readings->rejected];
    }

    /**
     * @return array{int, int, int} added, already there, left out
     */
    private function snapshotCounts(VehicleDataImportResult $result): array
    {
        return [$result->snapshots->added, $result->snapshots->alreadyThere, $result->snapshots->skipped];
    }

    /**
     * Read with the connection, which stays usable when a failed import has closed the entity manager.
     */
    private function assertNothingWritten(): void
    {
        $connection = $this->entityManager->getConnection();
        $id = $this->target->getId();
        $types = ['id' => UlidType::NAME];

        foreach (['lease_contract', 'odometer_reading'] as $table) {
            $sql = \sprintf('SELECT COUNT(*) FROM %s WHERE vehicle_id = :id', $table);

            self::assertSame(0, $connection->fetchOne($sql, ['id' => $id], $types), $table);
        }
    }

    private function manualReading(Vehicle $vehicle, string $at, int $kilometres): void
    {
        $this->entityManager->persist(
            OdometerReading::manual($vehicle, CarbonImmutable::parse($at), Distance::fromKilometres($kilometres)),
        );
        $this->entityManager->flush();
    }

    private function vehicle(string $vin, string $name): Vehicle
    {
        $vehicle = new Vehicle(new Vin($vin), $name, self::createUser());
        $this->entityManager->persist($vehicle);
        $this->entityManager->flush();

        return $vehicle;
    }

    private static function leaseData(int $allowancePerYear): LeaseContractData
    {
        $data = new LeaseContractData();
        $data->startDate = new \DateTimeImmutable('2026-01-01');
        $data->endDate = new \DateTimeImmutable('2027-01-01');
        $data->allowance = $allowancePerYear;
        $data->startOdometer = 0;
        $data->excessCostPerKm = '0.12';
        $data->currency = Currency::CHF;
        $data->tolerancePercent = 0;

        return $data;
    }
}
