<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Reading;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Reading\Domain\ReadingRejection;
use Polaris\Reading\Domain\ReadingSource;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Reading\Form\Model\OdometerReadingData;
use Polaris\Reading\Service\Enum\RecordOutcome;
use Polaris\Reading\Service\Exception\ReadingRejected;
use Polaris\Reading\Service\OdometerReadingService;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\DistanceUnit;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[CoversClass(OdometerReadingService::class)]
#[CoversClass(OdometerReading::class)]
final class OdometerReadingServiceTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    use SignsIn;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-10-20 12:00:00 UTC');
        $entityManager = self::entityManager();
        $this->vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000010'), 'Model 3', self::createUser());
        $entityManager->persist($this->vehicle);
        $entityManager->flush();
    }

    public function testFirstReadingIsStoredInMetres(): void
    {
        $reading = self::service()->recordManual($this->vehicle, self::data('2026-10-01 08:00', 12_345));

        self::assertSame(12_345_000, $reading->getOdometer()->metres());
        self::assertSame(ReadingSource::Manual, $reading->getSource());
        self::assertNull($reading->getOdometerRaw());
        self::assertSame('2026-10-01T08:00:00+00:00', $reading->getReadAt()->toIso8601String());
    }

    public function testMilesAreConvertedToMetres(): void
    {
        $reading = self::service()->recordManual($this->vehicle, self::data('2026-10-01 08:00', 1_000, DistanceUnit::Mile));

        self::assertSame(1_609_344, $reading->getOdometer()->metres());
        self::assertNull($reading->getOdometerRaw(), 'The raw value is kept only for readings that come from Tesla.');
    }

    public function testInputIsConvertedToUtc(): void
    {
        $data = self::data('2026-10-01 08:00', 100);
        $data->readAt = new \DateTimeImmutable('2026-10-01 10:00', new \DateTimeZone('Europe/Zurich'));

        $reading = self::service()->recordManual($this->vehicle, $data);

        self::assertSame('2026-10-01 08:00:00', $reading->getReadAt()->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $reading->getReadAt()->getTimezone()->getName());
    }

    public function testHigherReadingIsAccepted(): void
    {
        self::service()->recordManual($this->vehicle, self::data('2026-10-01 08:00', 10_000));
        $reading = self::service()->recordManual($this->vehicle, self::data('2026-10-02 08:00', 10_050));

        self::assertSame(10_050_000, $reading->getOdometer()->metres());
    }

    public function testEqualReadingIsStoredToo(): void
    {
        self::service()->recordManual($this->vehicle, self::data('2026-10-01 08:00', 10_000));
        self::service()->recordManual($this->vehicle, self::data('2026-10-02 08:00', 10_000));

        self::assertCount(2, self::service()->history($this->vehicle));
    }

    public function testLowerReadingIsRejectedAndNotStored(): void
    {
        self::service()->recordManual($this->vehicle, self::data('2026-10-01 08:00', 10_000));

        try {
            self::service()->recordManual($this->vehicle, self::data('2026-10-02 08:00', 9_999));
            self::fail('A lower reading must be rejected.');
        } catch (ReadingRejected $rejected) {
            self::assertSame(ReadingRejection::LowerThanPrevious, $rejected->reason);
            self::assertSame(10_000.0, $rejected->parameters['limit']);
            self::assertSame('km', $rejected->parameters['unit']);
            self::assertSame(CarbonImmutable::parse('2026-10-01 08:00', 'UTC')->getTimestamp(), $rejected->parameters['at']);
        }

        self::assertCount(1, self::service()->history($this->vehicle));
    }

    public function testLowerValueIsComparedInMetresWhateverTheUnit(): void
    {
        self::service()->recordManual($this->vehicle, self::data('2026-10-01 08:00', 10_000));

        // 6,000 mi is about 9,656 km, which is below 10,000 km.
        $this->expectException(ReadingRejected::class);

        self::service()->recordManual($this->vehicle, self::data('2026-10-02 08:00', 6_000, DistanceUnit::Mile));
    }

    public function testDuplicateReadAtIsRejected(): void
    {
        self::service()->recordManual($this->vehicle, self::data('2026-10-01 08:00', 10_000));

        try {
            self::service()->recordManual($this->vehicle, self::data('2026-10-01 08:00', 10_100));
            self::fail('A second reading at the same instant must be rejected.');
        } catch (ReadingRejected $rejected) {
            self::assertSame(ReadingRejection::DuplicateInstant, $rejected->reason);
        }
    }

    public function testBackfillMustFitBetweenItsNeighbours(): void
    {
        self::service()->recordManual($this->vehicle, self::data('2026-10-01 08:00', 10_000));
        self::service()->recordManual($this->vehicle, self::data('2026-10-10 08:00', 11_000));

        self::service()->recordManual($this->vehicle, self::data('2026-10-05 08:00', 10_500));

        foreach ([[9_500, ReadingRejection::LowerThanPrevious], [11_500, ReadingRejection::HigherThanNext]] as [$km, $expected]) {
            try {
                self::service()->recordManual($this->vehicle, self::data('2026-10-06 08:00', $km));
                self::fail('A backfill outside its neighbours must be rejected.');
            } catch (ReadingRejected $rejected) {
                self::assertSame($expected, $rejected->reason);
            }
        }
    }

    public function testReadingInTheFutureIsRejected(): void
    {
        try {
            self::service()->recordManual($this->vehicle, self::data('2026-10-20 12:01', 10_000));
            self::fail('A reading dated after now must be rejected.');
        } catch (ReadingRejected $rejected) {
            self::assertSame(ReadingRejection::InFuture, $rejected->reason);
        }

        // Exactly now is fine.
        self::assertSame(10_000_000, self::service()->recordManual($this->vehicle, self::data('2026-10-20 12:00', 10_000))->getOdometer()->metres());
    }

    public function testHistoryIsNewestFirstAndPerVehicle(): void
    {
        $other = new Vehicle(new Vin('5YJ3E7EB2NF000011'), 'Model Y', self::createUser());
        self::entityManager()->persist($other);
        self::entityManager()->flush();

        self::service()->recordManual($this->vehicle, self::data('2026-10-02 08:00', 10_100));
        self::service()->recordManual($this->vehicle, self::data('2026-10-01 08:00', 10_000));
        self::service()->recordManual($this->vehicle, self::data('2026-10-03 08:00', 10_200));
        self::service()->recordManual($other, self::data('2026-10-04 08:00', 5));

        $dates = array_map(static fn (OdometerReading $reading): string => $reading->getReadAt()->format('m-d'), self::service()->history($this->vehicle));

        self::assertSame(['10-03', '10-02', '10-01'], $dates);
    }

    public function testIncompleteDataIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::service()->recordManual($this->vehicle, new OdometerReadingData());
    }

    public function testStoredColumnsAreMetresAndUtc(): void
    {
        self::service()->recordManual($this->vehicle, self::data('2026-10-01 08:00', 100, DistanceUnit::Mile));
        self::entityManager()->clear();

        $row = self::entityManager()->getConnection()->fetchAssociative('SELECT odometer_m, odometer_raw, odometer_raw_unit, source, read_at FROM odometer_reading');

        self::assertIsArray($row);
        self::assertSame(160_934, $row['odometer_m']);
        self::assertNull($row['odometer_raw']);
        self::assertNull($row['odometer_raw_unit']);
        self::assertSame('manual', $row['source']);
        self::assertIsString($row['read_at']);
        self::assertStringStartsWith('2026-10-01 08:00:00', $row['read_at']);
    }

    public function testInstantsKeepMicrosecondPrecision(): void
    {
        $readAt = CarbonImmutable::parse('2026-10-01 08:00:00.123456', 'UTC');
        $entityManager = self::entityManager();
        $reading = OdometerReading::manual($this->vehicle, $readAt, Distance::fromKilometres(1));
        $entityManager->persist($reading);
        $entityManager->flush();
        $entityManager->clear();

        $reloaded = $entityManager->find(OdometerReading::class, $reading->getId());

        self::assertInstanceOf(OdometerReading::class, $reloaded);
        self::assertSame('2026-10-01 08:00:00.123456', $reloaded->getReadAt()->format('Y-m-d H:i:s.u'));
        self::assertSame(
            6,
            $entityManager->getConnection()->fetchOne("SELECT datetime_precision FROM information_schema.columns WHERE table_name = 'odometer_reading' AND column_name = 'read_at'"),
        );
    }

    public function testAValueReadFromTheCarIsStoredWithItsRawMiles(): void
    {
        $recording = self::service()->recordFromApi($this->vehicle, CarbonImmutable::parse('2026-10-01 08:00', 'UTC'), Distance::fromMiles(1_000.5));

        self::assertSame(RecordOutcome::Stored, $recording->outcome);
        self::assertSame(ReadingSource::Api, $recording->stored?->getSource());
        self::assertSame('1000.500', $recording->stored->getOdometerRaw());
        self::assertSame(DistanceUnit::Mile, $recording->stored->getOdometerRawUnit());
        self::assertSame(1_610_149, $recording->stored->getOdometer()->metres());
    }

    public function testTheSameValueAtALaterInstantIsNotStored(): void
    {
        self::service()->recordFromApi($this->vehicle, CarbonImmutable::parse('2026-10-01 08:00', 'UTC'), Distance::fromMiles(1_000.5));

        $recording = self::service()->recordFromApi($this->vehicle, CarbonImmutable::parse('2026-10-02 08:00', 'UTC'), Distance::fromMiles(1_000.5));

        self::assertSame(RecordOutcome::Unchanged, $recording->outcome);
        self::assertCount(1, self::service()->history($this->vehicle));
    }

    public function testTheSameValueAtALaterInstantIsNotedOnTheLatestReading(): void
    {
        self::service()->recordFromApi($this->vehicle, CarbonImmutable::parse('2026-10-01 08:00', 'UTC'), Distance::fromMiles(1_000.5));
        self::service()->recordFromApi($this->vehicle, CarbonImmutable::parse('2026-10-02 08:00', 'UTC'), Distance::fromMiles(1_000.5));
        // An earlier answer arriving late does not move the confirmation back.
        self::service()->recordFromApi($this->vehicle, CarbonImmutable::parse('2026-10-01 20:00', 'UTC'), Distance::fromMiles(1_000.5));

        $reading = self::service()->history($this->vehicle)[0];

        self::assertSame('2026-10-02T08:00:00+00:00', $reading->getConfirmedAt()?->toIso8601String());
    }

    public function testAConfirmationNeverGoesPastTheClock(): void
    {
        self::service()->recordFromApi($this->vehicle, CarbonImmutable::parse('2026-10-01 08:00', 'UTC'), Distance::fromMiles(1_000.5));

        self::service()->recordFromApi($this->vehicle, CarbonImmutable::parse('2026-10-21 08:00', 'UTC'), Distance::fromMiles(1_000.5));

        self::assertSame('2026-10-20T12:00:00+00:00', self::service()->history($this->vehicle)[0]->getConfirmedAt()?->toIso8601String());
    }

    public function testAReadingThatIsNotTheLatestIsNotConfirmed(): void
    {
        self::service()->recordFromApi($this->vehicle, CarbonImmutable::parse('2026-10-01 08:00', 'UTC'), Distance::fromMiles(1_000.5));
        self::service()->recordManual($this->vehicle, self::data('2026-10-03 08:00', 5_000));

        self::service()->recordFromApi($this->vehicle, CarbonImmutable::parse('2026-10-02 08:00', 'UTC'), Distance::fromMiles(1_000.5));

        $history = self::service()->history($this->vehicle);
        self::assertNull($history[1]->getConfirmedAt());
    }

    public function testAReadingStartsWithoutConfirmation(): void
    {
        self::service()->recordFromApi($this->vehicle, CarbonImmutable::parse('2026-10-01 08:00', 'UTC'), Distance::fromMiles(1_000.5));

        self::assertNull(self::service()->history($this->vehicle)[0]->getConfirmedAt());
    }

    public function testAReadingAtAnInstantThatIsAlreadyStoredIsNotStored(): void
    {
        self::service()->recordManual($this->vehicle, self::data('2026-10-01 08:00', 1_000));

        $recording = self::service()->recordFromApi($this->vehicle, CarbonImmutable::parse('2026-10-01 08:00', 'UTC'), Distance::fromKilometres(2_000));

        self::assertSame(RecordOutcome::Unchanged, $recording->outcome);
    }

    public function testAValueLowerThanThePreviousReadingIsRejectedWithoutAnException(): void
    {
        self::service()->recordManual($this->vehicle, self::data('2026-10-01 08:00', 10_000));

        $recording = self::service()->recordFromApi($this->vehicle, CarbonImmutable::parse('2026-10-02 08:00', 'UTC'), Distance::fromKilometres(9_000));

        self::assertSame(RecordOutcome::Rejected, $recording->outcome);
        self::assertSame(ReadingRejection::LowerThanPrevious, $recording->rejection);
        self::assertSame(10_000_000, $recording->conflicting?->getOdometer()->metres());
        self::assertCount(1, self::service()->history($this->vehicle));
    }

    public function testAValueHigherThanALaterReadingIsRejected(): void
    {
        self::service()->recordManual($this->vehicle, self::data('2026-10-03 08:00', 10_000));

        $recording = self::service()->recordFromApi($this->vehicle, CarbonImmutable::parse('2026-10-02 08:00', 'UTC'), Distance::fromKilometres(11_000));

        self::assertSame(ReadingRejection::HigherThanNext, $recording->rejection);
    }

    private static function data(string $readAtUtc, int $value, DistanceUnit $unit = DistanceUnit::Kilometre): OdometerReadingData
    {
        $data = new OdometerReadingData();
        $data->readAt = CarbonImmutable::parse($readAtUtc, 'UTC');
        $data->odometer = $value;
        $data->distanceUnit = $unit;

        return $data;
    }

    private static function service(): OdometerReadingService
    {
        return self::getContainer()->get(OdometerReadingService::class);
    }

    private static function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
