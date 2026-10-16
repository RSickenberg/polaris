<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Dashboard;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Dashboard\Domain\MileageRange;
use Polaris\Dashboard\Domain\MileageRangeBuilder;
use Polaris\Dashboard\Domain\MileageRangeKey;

#[CoversClass(MileageRangeBuilder::class)]
#[CoversClass(MileageRange::class)]
final class MileageRangeBuilderTest extends TestCase
{
    public function testAnActiveLeaseOffersEveryWindow(): void
    {
        $ranges = $this->build('2025-01-01', '2028-01-01', '2026-07-02 12:00:00', true);

        self::assertSame(['week', 'month', 'year', 'forecast', 'all'], self::keys($ranges));
        self::assertSame(['2026-06-25 12:00:00', '2026-07-02 12:00:00'], self::window($ranges[0]));
        self::assertSame(['2026-06-02 12:00:00', '2026-07-02 12:00:00'], self::window($ranges[1]));
        self::assertSame(['2025-07-02 12:00:00', '2026-07-02 12:00:00'], self::window($ranges[2]));
        self::assertSame(['2026-07-02 12:00:00', '2028-01-01 00:00:00'], self::window($ranges[3]));
        self::assertSame(['2025-01-01 00:00:00', '2028-01-01 00:00:00'], self::window($ranges[4]));
    }

    public function testLookBackWindowsNeverBeginBeforeTheLeaseStart(): void
    {
        $ranges = $this->build('2026-06-20', '2028-06-20', '2026-07-02 12:00:00', true);

        self::assertSame(['2026-06-20 00:00:00', '2026-07-02 12:00:00'], self::window($ranges[1]));
        self::assertSame(['2026-06-20 00:00:00', '2026-07-02 12:00:00'], self::window($ranges[2]));
    }

    public function testWithoutAProjectionThereIsNoForecastWindow(): void
    {
        $ranges = $this->build('2025-01-01', '2026-01-01', '2026-03-01 00:00:00', false);

        self::assertNotContains('forecast', self::keys($ranges));
    }

    public function testWindowsEntirelyAfterTheLeaseEndAreLeftOut(): void
    {
        $ranges = $this->build('2025-01-01', '2026-01-01', '2026-07-02 12:00:00', false);

        self::assertSame(['year', 'all'], self::keys($ranges));
    }

    public function testARangeMustEndAfterItStarts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MileageRange(MileageRangeKey::Week, CarbonImmutable::parse('2026-01-02', 'UTC'), CarbonImmutable::parse('2026-01-01', 'UTC'));
    }

    /**
     * @return non-empty-list<MileageRange>
     */
    private function build(string $start, string $end, string $now, bool $projection): array
    {
        return new MileageRangeBuilder()->build(
            CarbonImmutable::parse($start, 'UTC'),
            CarbonImmutable::parse($end, 'UTC'),
            CarbonImmutable::parse($now, 'UTC'),
            $projection,
        );
    }

    /**
     * @param list<MileageRange> $ranges
     *
     * @return list<string>
     */
    private static function keys(array $ranges): array
    {
        return array_map(static fn (MileageRange $range): string => $range->key->value, $ranges);
    }

    /**
     * @return array{string, string}
     */
    private static function window(MileageRange $range): array
    {
        return [$range->from->format('Y-m-d H:i:s'), $range->to->format('Y-m-d H:i:s')];
    }
}
