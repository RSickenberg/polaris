<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Shared\Twig;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Shared\Twig\LocalizedDatetimeExtension;
use Symfony\Component\Clock\MockClock;

#[CoversClass(LocalizedDatetimeExtension::class)]
final class LocalizedDatetimeExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function humanized(): iterable
    {
        // The clock reads Thursday 2 July 2026, 12:00 UTC.
        yield 'today' => ['2026-07-02 11:00:00', 'UTC', 'en', 'Today at 11:00 AM'];
        yield 'yesterday' => ['2026-07-01 08:30:00', 'UTC', 'en', 'Yesterday at 8:30 AM'];
        yield 'earlier this week' => ['2026-06-29 10:00:00', 'UTC', 'en', 'Last Monday at 10:00 AM'];
        yield 'older' => ['2026-05-01 10:00:00', 'UTC', 'en', 'May 1, 2026, 10:00 AM'];
        // 23:30 UTC the day before is already 01:30 the next day in Zurich (UTC+2 in summer).
        yield 'the day is the one of the time zone' => ['2026-07-01 23:30:00', 'Europe/Zurich', 'en', 'Today at 1:30 AM'];
        yield 'french today' => ['2026-07-02 11:00:00', 'UTC', 'fr', 'Aujourd’hui à 11:00'];
        yield 'french yesterday' => ['2026-07-01 08:30:00', 'UTC', 'fr', 'Hier à 08:30'];
    }

    #[DataProvider('humanized')]
    public function testHumanizedDatetime(string $at, string $timeZone, string $locale, string $expected): void
    {
        $extension = new LocalizedDatetimeExtension(new MockClock('2026-07-02 12:00:00 UTC'));

        self::assertSame($expected, str_replace("\u{202F}", ' ', $extension->humanize(CarbonImmutable::parse($at, 'UTC'), $timeZone, $locale)));
    }

    public function testTheZoneCanBeLeftOut(): void
    {
        $extension = new LocalizedDatetimeExtension(new MockClock('2026-07-02 12:00:00 UTC'));
        $at = CarbonImmutable::parse('2026-07-02 11:00:00', 'UTC');

        self::assertStringContainsString('UTC', $extension->format($at, 'UTC', 'en'));
        self::assertStringNotContainsString('UTC', $extension->format($at, 'UTC', 'en', false));
    }
}
