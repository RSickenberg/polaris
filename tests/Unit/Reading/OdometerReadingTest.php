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
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;

#[CoversClass(OdometerReading::class)]
final class OdometerReadingTest extends TestCase
{
    public function testManualReadingHasNoRawMiles(): void
    {
        $reading = OdometerReading::manual(self::vehicle(), self::utc('2026-10-01 08:00'), Distance::fromKilometres(12_345));

        self::assertSame(12_345_000, $reading->getOdometer()->metres());
        self::assertNull($reading->getOdometerMiles());
        self::assertSame(ReadingSource::Manual, $reading->getSource());
    }

    public function testManualReadingInMilesIsStoredInMetres(): void
    {
        $reading = OdometerReading::manual(self::vehicle(), self::utc('2026-10-01 08:00'), Distance::fromMiles(1_000));

        self::assertSame(1_609_344, $reading->getOdometer()->metres());
    }

    public function testTeslaReadingKeepsTheRawMilesAndConvertsToMetres(): void
    {
        $reading = OdometerReading::fromTesla(self::vehicle(), self::utc('2026-10-01 08:00'), '1000.500');

        self::assertSame('1000.500', $reading->getOdometerMiles());
        // 1000.5 mi x 1609.344 m = 1,610,148.672 m, rounded half up.
        self::assertSame(1_610_149, $reading->getOdometer()->metres());
        self::assertSame(ReadingSource::Api, $reading->getSource());
    }

    #[DataProvider('invalidMiles')]
    public function testTeslaReadingRejectsMalformedMiles(string $miles): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OdometerReading::fromTesla(self::vehicle(), self::utc('2026-10-01 08:00'), $miles);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidMiles(): iterable
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
