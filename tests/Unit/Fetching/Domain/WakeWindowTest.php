<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Fetching\Domain;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Fetching\Domain\WakeCapStatus;
use Polaris\Fetching\Domain\WakeWindow;

#[CoversClass(WakeWindow::class)]
#[CoversClass(WakeCapStatus::class)]
final class WakeWindowTest extends TestCase
{
    private const string NOW = '2026-10-27 12:00:00';

    public function testTheWakeIsGivenUpOnNinetySecondsAfterWakeUp(): void
    {
        $wokeAt = self::now();
        $deadline = WakeWindow::deadline($wokeAt)->getTimestamp();

        self::assertSame($wokeAt->getTimestamp() + 90, $deadline);
        self::assertFalse(WakeWindow::isOver($deadline, $wokeAt->addSeconds(89)));
        self::assertTrue(WakeWindow::isOver($deadline, $wokeAt->addSeconds(90)));
        self::assertTrue(WakeWindow::isOver($deadline, $wokeAt->addSeconds(91)));
    }

    public function testThePollRunsNineTimesBetweenTheWakeAndTheDeadline(): void
    {
        $wokeAt = self::now();
        $deadline = WakeWindow::deadline($wokeAt)->getTimestamp();
        $checks = 0;

        for ($at = $wokeAt->addSeconds(WakeWindow::POLL_INTERVAL_SECONDS); !WakeWindow::isOver($deadline, $at); $at = $at->addSeconds(WakeWindow::POLL_INTERVAL_SECONDS)) {
            ++$checks;
        }

        self::assertSame(8, $checks, 'Eight checks that keep waiting, then the ninth, at 90 s, gives up.');
    }

    public function testAWakeCountsForTwentyFourHoursAfterItWasSent(): void
    {
        $now = self::now();

        self::assertSame($now->subHours(24)->getTimestamp(), WakeWindow::capStart($now)->getTimestamp());
    }

    public function testBelowTheCapAWakeIsAllowed(): void
    {
        $status = WakeWindow::capStatus([self::now()->subHour(), self::now()->subHours(2)], 3);

        self::assertFalse($status->isReached());
        self::assertSame(2, $status->used);
        self::assertNull($status->availableAt);
    }

    public function testAtTheCapTheNextWakeIsAllowedWhenTheOldestCountedOneIsADayOld(): void
    {
        $wakes = [self::now()->subHour(), self::now()->subHours(5), self::now()->subHours(9)];

        $status = WakeWindow::capStatus($wakes, 3);

        self::assertTrue($status->isReached());
        self::assertSame(self::now()->subHours(9)->addDay()->getTimestamp(), $status->availableAt?->getTimestamp());
    }

    public function testWithACapOfOneTheMostRecentWakeDecides(): void
    {
        $status = WakeWindow::capStatus([self::now()->subHours(2), self::now()->subHours(20)], 1);

        self::assertSame(self::now()->subHours(2)->addDay()->getTimestamp(), $status->availableAt?->getTimestamp());
    }

    public function testACapOfZeroTurnsWakingOffForGood(): void
    {
        $status = WakeWindow::capStatus([], 0);

        self::assertTrue($status->isReached());
        self::assertNull($status->availableAt);
    }

    public function testAWakeIsPendingUntilTheDeadlineAndAGraceMargin(): void
    {
        $now = self::now();

        self::assertSame($now->subSeconds(120)->getTimestamp(), WakeWindow::pendingSince($now)->getTimestamp());
    }

    private static function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::NOW, 'UTC');
    }
}
