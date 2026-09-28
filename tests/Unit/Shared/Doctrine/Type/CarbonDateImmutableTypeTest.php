<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Shared\Doctrine\Type;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\ConversionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Shared\Doctrine\Type\CarbonDateImmutableType;

#[CoversClass(CarbonDateImmutableType::class)]
final class CarbonDateImmutableTypeTest extends TestCase
{
    private CarbonDateImmutableType $type;
    private PostgreSQLPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new CarbonDateImmutableType();
        $this->platform = new PostgreSQLPlatform();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set('UTC');
    }

    public function testDeclaresADateColumn(): void
    {
        self::assertSame('DATE', $this->type->getSQLDeclaration([], $this->platform));
    }

    public function testReadsMidnightUtcWhateverTheDefaultTimeZone(): void
    {
        date_default_timezone_set('Pacific/Kiritimati');

        $date = $this->type->convertToPHPValue('2026-01-15', $this->platform);

        self::assertInstanceOf(CarbonImmutable::class, $date);
        self::assertSame('2026-01-15T00:00:00+00:00', $date->toIso8601String());
        self::assertSame('UTC', $date->getTimezone()->getName());
    }

    public function testNullStaysNull(): void
    {
        self::assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    public function testWritesTheCalendarDate(): void
    {
        self::assertSame('2028-02-29', $this->type->convertToDatabaseValue(CarbonImmutable::parse('2028-02-29', 'UTC'), $this->platform));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidDatabaseValues(): iterable
    {
        yield 'not midnight' => [CarbonImmutable::parse('2026-01-15 00:00:01', 'UTC')];
        yield 'midnight in another time zone' => [CarbonImmutable::parse('2026-01-15', 'Europe/Zurich')];
        yield 'a string' => ['2026-01-15'];
    }

    #[DataProvider('invalidDatabaseValues')]
    public function testRefusesToWriteAnythingButMidnightUtc(mixed $value): void
    {
        $this->expectException(ConversionException::class);

        $this->type->convertToDatabaseValue($value, $this->platform);
    }

    public function testRefusesAnInvalidDatabaseValue(): void
    {
        $this->expectException(ConversionException::class);

        $this->type->convertToPHPValue('15.01.2026', $this->platform);
    }
}
