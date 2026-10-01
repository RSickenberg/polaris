<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Reading;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Shared\Domain\Distance;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Dates are stored in UTC and converted to the display time zone only in the template.
 */
#[CoversNothing]
final class HistoryTableTimeZoneTest extends KernelTestCase
{
    public function testDatesAcrossTheEndOfDaylightSavingTime(): void
    {
        // Europe/Zurich leaves summer time on 2026-10-25 at 01:00 UTC: 02:59 CEST is followed by 02:00 CET.
        $readings = [
            self::reading('2026-10-25 01:30', 3),
            self::reading('2026-10-25 00:30', 2),
            self::reading('2026-10-24 22:30', 1),
        ];

        $html = $this->render($readings, 'Europe/Zurich');

        // 22:30 UTC is 00:30 CEST (UTC+2) the next day; 00:30 UTC is 02:30 CEST; 01:30 UTC is 02:30 CET (UTC+1).
        self::assertSame(['Oct 25, 2026, 2:30 AM GMT+1', 'Oct 25, 2026, 2:30 AM GMT+2', 'Oct 25, 2026, 12:30 AM GMT+2'], $this->dates($html));
        // The two 02:30 rows are an hour apart in reality: the offset tells them apart, and the list keeps UTC order, newest first.
        self::assertSame([3.0, 2.0, 1.0], $this->kilometres($html));
    }

    public function testDatesAcrossTheStartOfDaylightSavingTime(): void
    {
        // Europe/Zurich enters summer time on 2026-03-29 at 01:00 UTC: 01:59 CET jumps to 03:00 CEST.
        $html = $this->render([self::reading('2026-03-29 01:00', 2), self::reading('2026-03-29 00:59', 1)], 'Europe/Zurich');

        self::assertSame(['Mar 29, 2026, 3:00 AM GMT+2', 'Mar 29, 2026, 1:59 AM GMT+1'], $this->dates($html));
    }

    public function testUtcIsShownAsStored(): void
    {
        $html = $this->render([self::reading('2026-10-25 01:30', 1)], 'UTC');

        self::assertSame(['Oct 25, 2026, 1:30 AM UTC'], $this->dates($html));
        self::assertStringContainsString('Read at (UTC)', $html);
    }

    public function testNoReading(): void
    {
        self::assertStringContainsString('No reading yet.', $this->render([], 'UTC'));
    }

    /**
     * @param list<OdometerReading> $readings
     */
    private function render(array $readings, string $timeZone): string
    {
        self::bootKernel();

        return self::getContainer()->get(Environment::class)->render('reading/_table.html.twig', ['readings' => $readings, 'timeZone' => $timeZone]);
    }

    /**
     * @return list<string>
     */
    private function dates(string $html): array
    {
        preg_match_all('#<tr>\s*<td>(.*?)</td>#s', $html, $matches);

        return array_map(static fn (string $date): string => preg_replace('/\s+/u', ' ', str_replace("\u{202f}", ' ', trim($date))) ?? '', $matches[1]);
    }

    /**
     * @return list<float>
     */
    private function kilometres(string $html): array
    {
        preg_match_all('#<td>[^<]*</td>\s*<td>([\d.,]+)</td>#', $html, $matches);

        return array_map(static fn (string $value): float => (float) str_replace(',', '', $value), $matches[1]);
    }

    private static function reading(string $readAtUtc, int $km): OdometerReading
    {
        return OdometerReading::manual(new Vehicle(new Vin('5YJ3E7EB2NF000040'), 'Model 3'), CarbonImmutable::parse($readAtUtc, 'UTC'), Distance::fromKilometres($km));
    }
}
