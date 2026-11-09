<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Fetching\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Fetching\Domain\RefreshOutcome;

#[CoversClass(RefreshOutcome::class)]
final class RefreshOutcomeTest extends TestCase
{
    public function testOnlyQueuedAndWakingAreNotSettled(): void
    {
        foreach (RefreshOutcome::cases() as $outcome) {
            $pending = \in_array($outcome, [RefreshOutcome::Queued, RefreshOutcome::Waking], true);

            self::assertSame(!$pending, $outcome->isSettled(), $outcome->name);
        }
    }

    public function testOnlyAnAsleepOrOfflineCarCanBeWoken(): void
    {
        foreach (RefreshOutcome::cases() as $outcome) {
            $wakeable = \in_array($outcome, [RefreshOutcome::Asleep, RefreshOutcome::Offline], true);

            self::assertSame($wakeable, $outcome->isWakeable(), $outcome->name);
        }
    }
}
