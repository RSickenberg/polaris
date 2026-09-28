<?php

declare(strict_types=1);

namespace Polaris\Shared\Doctrine\Type;

use Carbon\CarbonImmutable;
use Carbon\Doctrine\CarbonImmutableType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;

/**
 * Replaces the built-in datetime_immutable type: an instant stored as a UTC timestamp
 * (without time zone) that hydrates as a CarbonImmutable in UTC.
 *
 * The parent type from carbonphp/carbon-doctrine-types writes the wall-clock time of
 * the value and parses with the PHP default time zone. This type converts to UTC
 * before writing and parses as UTC, so no value drifts whatever its time zone.
 */
final class CarbonDateTimeImmutableType extends CarbonImmutableType
{
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof \DateTimeInterface) {
            throw InvalidType::new($value, self::class, ['null', 'DateTimeInterface']);
        }

        return parent::convertToDatabaseValue(CarbonImmutable::instance($value)->utc(), $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?CarbonImmutable
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::class, ['null', 'string']);
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (\Exception $exception) {
            throw ValueNotConvertible::new($value, self::class, 'Y-m-d H:i:s.u', $exception);
        }
    }
}
