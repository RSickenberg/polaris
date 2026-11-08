<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Dashboard;

use PHPUnit\Framework\Attributes\CoversNothing;
use Polaris\Dashboard\Service\Dto\ComparisonFigures;
use Polaris\Dashboard\Service\Dto\ComparisonRow;
use Polaris\Dashboard\Service\Enum\ComparisonPeriod;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\DistanceDelta;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * The panel component on its own: no database, only the template and the translations.
 */
#[CoversNothing]
final class ComparisonPanelTest extends KernelTestCase
{
    public function testARowShowsWhatWasDrivenAgainstWhatWasExpectedAndTheDifference(): void
    {
        $panel = $this->panel('en');

        $today = $panel->filter('[data-period=today]');
        self::assertSame('Today', $this->text($today->filter('td')->first()->filter('span')->first()));
        self::assertSame('50 / 45 km', $this->text($today->filter('[data-figure=figures]')));
        self::assertSame('+5 km', $this->text($today->filter('[data-figure=delta]')));
        self::assertSame('+11%', $this->text($today->filter('[data-figure=ratio]')));
    }

    public function testALessThanExpectedRowHasAMinusAndAZeroRowNoSign(): void
    {
        $panel = $this->panel('en');

        self::assertSame('−2 km', $this->text($panel->filter('[data-period=week] [data-figure=delta]')));
        self::assertSame('−5%', $this->text($panel->filter('[data-period=week] [data-figure=ratio]')));
        self::assertSame('0 km', $this->text($panel->filter('[data-period=month] [data-figure=delta]')));
    }

    public function testNothingExpectedShowsTheDifferenceWithoutAPercentage(): void
    {
        $panel = $this->panel('en');

        self::assertSame('+3 km', $this->text($panel->filter('[data-period=two_weeks] [data-figure=delta]')));
        self::assertCount(0, $panel->filter('[data-period=two_weeks] [data-figure=ratio]'));
    }

    public function testARowWithoutHistorySaysSoInsteadOfAFigure(): void
    {
        $panel = $this->panel('en', withHistory: false);

        $row = $panel->filter('[data-period=week]');
        self::assertSame('Not enough history', $this->text($row->filter('[data-no-history]')));
        self::assertCount(0, $row->filter('[data-figure]'));
    }

    public function testTheFrenchPanelUsesFrenchWordsAndAFrenchPercentage(): void
    {
        $panel = $this->panel('fr');

        $period = $panel->filter('[data-period=today] td')->first()->filter('span')->first();

        self::assertSame('Aujourd’hui', $this->text($period));
        self::assertSame('+11 %', $this->text($panel->filter('[data-period=today] [data-figure=ratio]')));
    }

    protected function tearDown(): void
    {
        \Locale::setDefault('en');
        parent::tearDown();
    }

    public function testAnInfoButtonOpensTheExplanationOfThePanel(): void
    {
        $panel = $this->panel('en', help: 'First line.\\nSecond line.');

        $button = $panel->filter('[data-help=compare-budget]');
        self::assertCount(1, $button);
        self::assertSame('About “Title”', $button->attr('aria-label'));
        self::assertStringContainsString('First line.', $panel->filter('dialog')->text());
        self::assertStringContainsString('Second line.', $panel->filter('dialog')->text());
        self::assertStringNotContainsString('\\n', $panel->filter('dialog')->text());
        self::assertCount(1, $panel->filter('dialog br'));
    }

    public function testWithoutAnExplanationThereIsNoInfoButton(): void
    {
        self::assertCount(0, $this->panel('en')->filter('[data-help]'));
    }

    private function panel(string $locale, bool $withHistory = true, ?string $help = null): Crawler
    {
        self::bootKernel();
        $translator = self::getContainer()->get('translator');
        $translator->setLocale($locale);
        // The number formats of Twig follow the default locale of PHP, which a request sets in the application.
        \Locale::setDefault($locale);
        $rows = [
            new ComparisonRow(ComparisonPeriod::Today, $this->figures(50_000, 45_000)),
            new ComparisonRow(ComparisonPeriod::LastWeek, $withHistory ? $this->figures(38_000, 40_000) : null),
            new ComparisonRow(ComparisonPeriod::LastTwoWeeks, $withHistory ? $this->figures(3_000, 0) : null),
            new ComparisonRow(ComparisonPeriod::LastMonth, $withHistory ? $this->figures(9_000, 9_000) : null),
        ];

        $template = "{{ component('Dashboard:ComparisonPanel', {key: 'budget', title: 'Title', basis: 'Basis', rows: rows, help: help}) }}";
        $html = self::getContainer()->get(Environment::class)
            ->createTemplate($template)
            ->render(['rows' => $rows, 'help' => $help]);

        return new Crawler($html);
    }

    private function figures(int $actual, int $expected): ComparisonFigures
    {
        $delta = DistanceDelta::between(Distance::fromMetres($expected), Distance::fromMetres($actual));

        $ratio = 0 === $expected ? null : $delta->metres() / $expected;

        return new ComparisonFigures(Distance::fromMetres($actual), Distance::fromMetres($expected), $delta, $ratio);
    }

    private function text(Crawler $node): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $node->text()));
    }
}
