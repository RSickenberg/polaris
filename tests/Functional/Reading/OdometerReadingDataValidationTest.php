<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Reading;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Polaris\Reading\Form\Model\OdometerReadingData;
use Polaris\Shared\Domain\DistanceUnit;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(OdometerReadingData::class)]
final class OdometerReadingDataValidationTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{int, DistanceUnit, bool}>
     */
    public static function values(): iterable
    {
        yield 'zero' => [0, DistanceUnit::Kilometre, true];
        yield 'a normal value' => [123_456, DistanceUnit::Kilometre, true];
        yield 'the limit in km' => [1_000_000, DistanceUnit::Kilometre, true];
        yield 'above the limit in km' => [1_000_001, DistanceUnit::Kilometre, false];
        yield 'the limit in miles is lower' => [621_371, DistanceUnit::Mile, true];
        yield 'above the limit in miles' => [621_372, DistanceUnit::Mile, false];
        yield 'an overflowing value' => [5_000_000, DistanceUnit::Kilometre, false];
        yield 'negative' => [-1, DistanceUnit::Kilometre, false];
    }

    #[DataProvider('values')]
    public function testOdometerRange(int $value, DistanceUnit $unit, bool $valid): void
    {
        $data = new OdometerReadingData();
        $data->readAt = new \DateTimeImmutable('2026-10-01 08:00 UTC');
        $data->odometer = $value;
        $data->distanceUnit = $unit;

        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($data);

        self::assertSame($valid, 0 === \count($violations));
    }

    public function testMissingValuesAreViolations(): void
    {
        $violations = self::getContainer()->get(ValidatorInterface::class)->validate(new OdometerReadingData());

        self::assertCount(2, $violations);
    }
}
