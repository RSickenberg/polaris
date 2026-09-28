<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Shared\Doctrine\Type;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\ConversionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Shared\Doctrine\Type\CarbonDateTimeImmutableType;

#[CoversClass(CarbonDateTimeImmutableType::class)]
final class CarbonDateTimeImmutableTypeTest extends TestCase
{
    private CarbonDateTimeImmutableType $type;
    private PostgreSQLPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new CarbonDateTimeImmutableType();
        $this->platform = new PostgreSQLPlatform();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set('UTC');
    }

    public function testDeclaresATimestampWithoutTimeZone(): void
    {
        self::assertSame('TIMESTAMP(6) WITHOUT TIME ZONE', $this->type->getSQLDeclaration([], $this->platform));
    }

    public function testWritesTheInstantInUtc(): void
    {
        $zurich = CarbonImmutable::parse('2026-07-01 10:30:00.123456', 'Europe/Zurich');

        self::assertSame('2026-07-01 08:30:00.123456', $this->type->convertToDatabaseValue($zurich, $this->platform));
        self::assertSame('2026-07-01 08:30:00.000000', $this->type->convertToDatabaseValue(new \DateTimeImmutable('2026-07-01 10:30', new \DateTimeZone('Europe/Zurich')), $this->platform));
    }

    public function testReadsAsUtcWhateverTheDefaultTimeZone(): void
    {
        date_default_timezone_set('America/New_York');

        $instant = $this->type->convertToPHPValue('2026-07-01 08:30:00.123456', $this->platform);

        self::assertInstanceOf(CarbonImmutable::class, $instant);
        self::assertSame('2026-07-01T08:30:00.123456+00:00', $instant->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('UTC', $instant->getTimezone()->getName());
    }

    public function testConvertsADateTimeToUtc(): void
    {
        $instant = $this->type->convertToPHPValue(new \DateTimeImmutable('2026-07-01 10:30', new \DateTimeZone('Europe/Zurich')), $this->platform);

        self::assertInstanceOf(CarbonImmutable::class, $instant);
        self::assertSame('2026-07-01T08:30:00+00:00', $instant->toIso8601String());
    }

    public function testNullStaysNull(): void
    {
        self::assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    public function testRefusesToWriteAString(): void
    {
        $this->expectException(ConversionException::class);

        $this->type->convertToDatabaseValue('2026-07-01 08:30:00', $this->platform);
    }

    public function testRefusesAnInvalidDatabaseValue(): void
    {
        $this->expectException(ConversionException::class);

        $this->type->convertToPHPValue('not a date', $this->platform);
    }
}
