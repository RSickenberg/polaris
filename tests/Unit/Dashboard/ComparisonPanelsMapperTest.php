<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Dashboard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Dashboard\Service\ComparisonPanelsMapper;
use Polaris\Dashboard\Service\Enum\ComparisonPeriod;
use Polaris\Lease\Domain\Comparison;
use Polaris\Lease\Domain\ComparisonWindow;
use Polaris\Lease\Domain\PredictionComparison;
use Polaris\Lease\Domain\WindowComparison;
use Polaris\Shared\Domain\Distance;

#[CoversClass(ComparisonPanelsMapper::class)]
final class ComparisonPanelsMapperTest extends TestCase
{
    public function testTheThreeComparisonsBecomeThreePanelsOfFiveRowsInOrder(): void
    {
        $comparison = Comparison::of(Distance::fromMetres(55_000), Distance::fromMetres(50_000));
        $rows = array_map(
            static fn (ComparisonWindow $window): WindowComparison => new WindowComparison(
                $window,
                ComparisonWindow::LastTwoWeeks === $window ? null : $comparison,
                ComparisonWindow::Today === $window ? 2 : 0,
            ),
            ComparisonWindow::cases(),
        );

        $panels = new ComparisonPanelsMapper()->map(new PredictionComparison($rows, $rows, $rows));

        foreach ([$panels->budget, $panels->pace, $panels->projectedEnd] as $panel) {
            self::assertSame(
                [
                    ComparisonPeriod::Today,
                    ComparisonPeriod::Yesterday,
                    ComparisonPeriod::LastWeek,
                    ComparisonPeriod::LastTwoWeeks,
                    ComparisonPeriod::LastMonth,
                ],
                array_map(static fn ($row): ComparisonPeriod => $row->period, $panel),
            );
        }

        self::assertFalse($panels->recalculating);
        self::assertTrue(
            new ComparisonPanelsMapper()->map(new PredictionComparison($rows, $rows, $rows, true))->recalculating,
        );

        $figures = $panels->budget[0]->figures;
        self::assertNotNull($figures);
        self::assertSame(55_000, $figures->actual->metres());
        self::assertSame(50_000, $figures->expected->metres());
        self::assertSame(5_000, $figures->delta->metres());
        self::assertSame(0.1, $figures->ratio);
        self::assertNull($panels->budget[3]->figures, 'No result stays no figures.');
        self::assertSame(2, $panels->budget[0]->fallbackDays);
        self::assertSame(0, $panels->budget[1]->fallbackDays);
    }
}
