<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Fetching\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Fetching\Domain\RefreshOutcome;

#[CoversClass(RefreshOutcome::class)]
final class RefreshOutcomeTest extends TestCase
{
    public function testEveryOutcomeHasAToastVariantAndOnlyQueuedIsNotSettled(): void
    {
        $variants = ['success', 'warning', 'info', 'danger'];

        foreach (RefreshOutcome::cases() as $outcome) {
            self::assertContains($outcome->toastVariant(), $variants);
            self::assertSame(RefreshOutcome::Queued !== $outcome, $outcome->isSettled());
        }
    }

    public function testTheVariantSaysWhatTheUserCanDoAboutIt(): void
    {
        self::assertSame('success', RefreshOutcome::Stored->toastVariant());
        self::assertSame('warning', RefreshOutcome::Asleep->toastVariant());
        self::assertSame('danger', RefreshOutcome::NotConnected->toastVariant());
    }
}
