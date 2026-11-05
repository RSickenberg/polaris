<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Lease\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Lease\Domain\LeaseStatus;
use Polaris\Lease\Domain\SnapshotPolicy;
use Symfony\Component\Clock\MockClock;

#[CoversClass(SnapshotPolicy::class)]
final class SnapshotPolicyTest extends TestCase
{
    #[DataProvider('statuses')]
    public function testOnlyAnActiveOrEndedLeaseIsCovered(LeaseStatus $status, bool $covered): void
    {
        self::assertSame($covered, SnapshotPolicy::covers($status));
    }

    /**
     * @return iterable<string, array{LeaseStatus, bool}>
     */
    public static function statuses(): iterable
    {
        yield 'active' => [LeaseStatus::Active, true];
        yield 'ended' => [LeaseStatus::Ended, true];
        yield 'returned' => [LeaseStatus::Returned, false];
        yield 'draft' => [LeaseStatus::Draft, false];
    }

    public function testTheDayIsTheUtcDateOfTheClock(): void
    {
        $day = SnapshotPolicy::dayOf(new MockClock('2026-07-02 23:59:59 UTC'));

        self::assertSame('2026-07-02T00:00:00+00:00', $day->toIso8601String());
    }

    public function testTheDayFollowsUtcWhateverTheClockZone(): void
    {
        // 01:30 on 3 July in Zurich (UTC+2) is still 2 July in UTC.
        $day = SnapshotPolicy::dayOf(new MockClock('2026-07-03 01:30:00 Europe/Zurich'));

        self::assertSame('2026-07-02T00:00:00+00:00', $day->toIso8601String());
    }
}
