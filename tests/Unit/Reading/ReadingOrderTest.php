<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Reading;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Reading\Domain\ReadingOrder;
use Polaris\Reading\Domain\ReadingRejection;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\OdometerSample;

#[CoversClass(ReadingOrder::class)]
final class ReadingOrderTest extends TestCase
{
    /**
     * @return iterable<string, array{?int, ?int, int, ?ReadingRejection}>
     */
    public static function cases(): iterable
    {
        yield 'first reading' => [null, null, 10_000, null];
        yield 'higher than the previous' => [10_000, null, 10_500, null];
        yield 'equal to the previous' => [10_000, null, 10_000, null];
        yield 'lower than the previous' => [10_000, null, 9_999, ReadingRejection::LowerThanPrevious];
        yield 'backfill between two readings' => [10_000, 11_000, 10_500, null];
        yield 'backfill equal to both neighbours' => [10_000, 10_000, 10_000, null];
        yield 'backfill lower than the previous' => [10_000, 11_000, 9_500, ReadingRejection::LowerThanPrevious];
        yield 'backfill higher than the next' => [10_000, 11_000, 11_500, ReadingRejection::HigherThanNext];
        yield 'before the first reading, lower than the next' => [null, 11_000, 500, null];
        yield 'before the first reading, higher than the next' => [null, 11_000, 11_001, ReadingRejection::HigherThanNext];
    }

    #[DataProvider('cases')]
    public function testCheck(?int $previousKm, ?int $nextKm, int $newKm, ?ReadingRejection $expected): void
    {
        $order = new ReadingOrder();

        $result = $order->check(
            self::sample('2026-10-05 12:00', $newKm),
            null === $previousKm ? null : self::sample('2026-10-01 12:00', $previousKm),
            null === $nextKm ? null : self::sample('2026-10-10 12:00', $nextKm),
        );

        self::assertSame($expected, $result);
    }

    private static function sample(string $readAt, int $km): OdometerSample
    {
        return new OdometerSample(CarbonImmutable::parse($readAt, 'UTC'), Distance::fromKilometres($km));
    }
}
