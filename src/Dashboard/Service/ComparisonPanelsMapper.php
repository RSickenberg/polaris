<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Service;

use Polaris\Dashboard\Service\Dto\ComparisonFigures;
use Polaris\Dashboard\Service\Dto\ComparisonPanels;
use Polaris\Dashboard\Service\Dto\ComparisonRow;
use Polaris\Dashboard\Service\Enum\ComparisonPeriod;
use Polaris\Lease\Domain\Comparison;
use Polaris\Lease\Domain\ComparisonWindow;
use Polaris\Lease\Domain\PredictionComparison;
use Polaris\Lease\Domain\WindowComparison;

/**
 * Turns the comparison of the Lease module into the dashboard's own types.
 */
final readonly class ComparisonPanelsMapper
{
    public function map(PredictionComparison $comparison): ComparisonPanels
    {
        return new ComparisonPanels(
            $this->rows($comparison->budget),
            $this->rows($comparison->pace),
            $this->rows($comparison->projectedEnd),
        );
    }

    /**
     * @param list<WindowComparison> $windows
     *
     * @return list<ComparisonRow>
     */
    private function rows(array $windows): array
    {
        $rows = [];

        foreach ($windows as $window) {
            $result = $window->comparison;
            $rows[] = new ComparisonRow(
                self::period($window->window),
                null === $result ? null : $this->figures($result),
                $window->fallbackDays,
            );
        }

        return $rows;
    }

    private function figures(Comparison $result): ComparisonFigures
    {
        return new ComparisonFigures($result->actual, $result->expected, $result->delta, $result->ratio);
    }

    private static function period(ComparisonWindow $window): ComparisonPeriod
    {
        return match ($window) {
            ComparisonWindow::Today => ComparisonPeriod::Today,
            ComparisonWindow::LastWeek => ComparisonPeriod::LastWeek,
            ComparisonWindow::LastTwoWeeks => ComparisonPeriod::LastTwoWeeks,
            ComparisonWindow::LastMonth => ComparisonPeriod::LastMonth,
        };
    }
}
