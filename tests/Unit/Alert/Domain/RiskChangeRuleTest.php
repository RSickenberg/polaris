<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Alert\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Alert\Domain\AlertDecision;
use Polaris\Alert\Domain\RiskChangeRule;

#[CoversClass(RiskChangeRule::class)]
final class RiskChangeRuleTest extends TestCase
{
    /**
     * @return iterable<string, array{?int, int, AlertDecision}>
     */
    public static function decisions(): iterable
    {
        yield 'first assessment, best level' => [null, 0, AlertDecision::Baseline];
        yield 'first assessment, worst level' => [null, 2, AlertDecision::Baseline];
        yield 'same level' => [1, 1, AlertDecision::Nothing];
        yield 'same worst level' => [2, 2, AlertDecision::Nothing];
        yield 'ok to watch' => [0, 1, AlertDecision::Notify];
        yield 'ok to over' => [0, 2, AlertDecision::Notify];
        yield 'watch to over' => [1, 2, AlertDecision::Notify];
        yield 'over to watch' => [2, 1, AlertDecision::Baseline];
        yield 'over to ok' => [2, 0, AlertDecision::Baseline];
        yield 'watch to ok' => [1, 0, AlertDecision::Baseline];
    }

    #[DataProvider('decisions')]
    public function testDecision(?int $last, int $current, AlertDecision $expected): void
    {
        self::assertSame($expected, RiskChangeRule::decide($last, $current));
    }
}
