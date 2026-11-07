<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Lease\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Lease\Domain\LeaseStatus;
use Polaris\Lease\Domain\SnapshotPolicy;

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
}
