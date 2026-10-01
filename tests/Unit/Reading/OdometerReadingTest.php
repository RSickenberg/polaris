<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Reading;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Reading\Domain\ReadingSource;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\DistanceUnit;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;

#[CoversClass(OdometerReading::class)]
final class OdometerReadingTest extends TestCase
{
    public function testManualReadingHasNoRawValue(): void
    {
        $reading = OdometerReading::manual(self::vehicle(), self::utc('2026-10-01 08:00'), Distance::fromKilometres(12_345));

        self::assertSame(12_345_000, $reading->getOdometer()->metres());
        self::assertNull($reading->getOdometerRaw());
        self::assertNull($reading->getOdometerRawUnit());
        self::assertSame(ReadingSource::Manual, $reading->getSource());
    }

    public function testManualReadingInMilesIsStoredInMetres(): void
    {
        $reading = OdometerReading::manual(self::vehicle(), self::utc('2026-10-01 08:00'), Distance::fromMiles(1_000));

        self::assertSame(1_609_344, $reading->getOdometer()->metres());
    }

    public function testTeslaReadingInMilesKeepsTheRawValueAndUnit(): void
    {
        $reading = OdometerReading::fromTesla(self::vehicle(), self::utc('2026-10-01 08:00'), '1000.500', DistanceUnit::Mile);

        self::assertSame('1000.500', $reading->getOdometerRaw());
        self::assertSame(DistanceUnit::Mile, $reading->getOdometerRawUnit());
        // 1000.5 mi x 1609.344 m = 1,610,148.672 m, rounded half up.
        self::assertSame(1_610_149, $reading->getOdometer()->metres());
        self::assertSame(ReadingSource::Api, $reading->getSource());
    }

    public function testTeslaReadingInKilometresIsConvertedByItsUnit(): void
    {
        $reading = OdometerReading::fromTesla(self::vehicle(), self::utc('2026-10-01 08:00'), '1000.500', DistanceUnit::Kilometre);

        self::assertSame('1000.500', $reading->getOdometerRaw());
        self::assertSame(DistanceUnit::Kilometre, $reading->getOdometerRawUnit());
        self::assertSame(1_000_500, $reading->getOdometer()->metres());
    }

    #[DataProvider('invalidRaw')]
    public function testTeslaReadingRejectsMalformedValues(string $raw): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OdometerReading::fromTesla(self::vehicle(), self::utc('2026-10-01 08:00'), $raw, DistanceUnit::Mile);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRaw(): iterable
    {
        yield 'negative' => ['-1'];
        yield 'too many decimals' => ['1.2345'];
        yield 'not a number' => ['abc'];
        yield 'empty' => [''];
    }

    public function testRejectsANonUtcInstant(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OdometerReading::manual(self::vehicle(), CarbonImmutable::parse('2026-10-01 08:00', 'Europe/Zurich'), Distance::fromKilometres(1));
    }

    private static function utc(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'UTC');
    }

    private static function vehicle(): Vehicle
    {
        return new Vehicle(new Vin('5YJ3E7EB2NF000001'), 'Model 3');
    }
}
