<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Fetching;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Fetching\Domain\FetchWindow;

#[CoversClass(FetchWindow::class)]
final class FetchWindowTest extends TestCase
{
    public function testTheWindowOpensAtTheSlotAndClosesThreeHoursLater(): void
    {
        $slot = CarbonImmutable::parse('2026-10-25 19:00', 'UTC');

        self::assertFalse(FetchWindow::isOpen($slot, $slot->subSecond()));
        self::assertTrue(FetchWindow::isOpen($slot, $slot));
        self::assertTrue(FetchWindow::isOpen($slot, $slot->addHours(3)->subSecond()));
        self::assertFalse(FetchWindow::isOpen($slot, $slot->addHours(3)));
    }

    public function testTheFourthCheckIsTheLastOne(): void
    {
        self::assertFalse(FetchWindow::isLastAttempt(0));
        self::assertFalse(FetchWindow::isLastAttempt(2));
        self::assertTrue(FetchWindow::isLastAttempt(3));
    }

    public function testRetriesStayOnTheHoursAfterTheSlot(): void
    {
        $slot = CarbonImmutable::parse('2026-10-25 19:05', 'UTC');

        self::assertSame(3600, FetchWindow::retryDelaySeconds($slot, 0, $slot));
        self::assertSame(3600 - 240, FetchWindow::retryDelaySeconds($slot, 0, $slot->addMinutes(4)));
        self::assertSame(3600, FetchWindow::retryDelaySeconds($slot, 1, $slot->addHours(1)));
        self::assertSame(0, FetchWindow::retryDelaySeconds($slot, 0, $slot->addHours(2)), 'Never negative.');
    }
}
