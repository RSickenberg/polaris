<?php

declare(strict_types=1);

namespace Polaris\Shared\Doctrine\Type;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;

/**
 * A calendar date (SQL DATE) that hydrates as a CarbonImmutable at midnight UTC.
 *
 * carbonphp/carbon-doctrine-types only provides datetime types, hence this type.
 * Reading never depends on the PHP default time zone. Writing refuses a value that is
 * not midnight UTC: converting a date from another time zone can move it by one day,
 * so a silent conversion would store the wrong date.
 */
final class CarbonDateImmutableType extends DateImmutableType
{
    public const string NAME = 'carbon_date_immutable';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof \DateTimeInterface
            || 0 !== $value->getOffset()
            || '00:00:00.000000' !== $value->format('H:i:s.u')
        ) {
            throw InvalidType::new($value, self::class, ['null', 'DateTimeInterface at midnight UTC']);
        }

        return $value->format($platform->getDateFormatString());
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?CarbonImmutable
    {
        if (null === $value || $value instanceof CarbonImmutable) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::class, ['null', 'string']);
        }

        try {
            $date = CarbonImmutable::createFromFormat('!' . $platform->getDateFormatString(), $value, 'UTC');
        } catch (\InvalidArgumentException $exception) {
            throw InvalidFormat::new($value, self::class, $platform->getDateFormatString(), $exception);
        }

        if (!$date instanceof CarbonImmutable) {
            throw InvalidFormat::new($value, self::class, $platform->getDateFormatString());
        }

        return $date;
    }
}
