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

    public function testTheGapIsAGreenPillWhenItIsNotMoreThanExpectedAndARedOneWhenItIs(): void
    {
        $panel = $this->panel('en');

        // Today +5 km and two weeks +3 km are more than expected; week -2 km and month 0 km are not.
        self::assertSame('bad', $panel->filter('[data-period=today] [data-figure=delta]')->attr('data-tone'));
        self::assertSame('bad', $panel->filter('[data-period=two_weeks] [data-figure=delta]')->attr('data-tone'));
        self::assertSame('good', $panel->filter('[data-period=week] [data-figure=delta]')->attr('data-tone'));
        self::assertSame('good', $panel->filter('[data-period=month] [data-figure=delta]')->attr('data-tone'));
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

    public function testWhileTheSnapshotsAreRecalculatedTheEmptyRowsSayIt(): void
    {
        $panel = $this->panel('en', withHistory: false, recalculating: true);

        self::assertSame(
            'You changed your lease terms. The past rows are being recalculated with them and come back in a moment;'
            . ' reload the page to see them.',
            $this->text($panel->filter('[data-recalculating]')),
        );
        self::assertSame('Recalculating', $this->text($panel->filter('[data-period=week] [data-no-history]')));
        self::assertSame('Recalcul en cours', $this->text(
            $this->panel('fr', withHistory: false, recalculating: true)->filter('[data-period=week] [data-no-history]'),
        ));
        self::assertCount(0, $this->panel('en', withHistory: false)->filter('[data-recalculating]'));
    }

    public function testTodayThatFellBackSaysSinceWhenItStarted(): void
    {
        $yesterday = $this->panel('en', todayFallbackDays: 1)->filter('[data-period=today] [data-fallback-days]');
        $earlier = $this->panel('en', todayFallbackDays: 3)->filter('[data-period=today] [data-fallback-days]');
        $french = $this->panel('fr', todayFallbackDays: 1)->filter('[data-period=today] [data-fallback-days]');

        self::assertSame('Since yesterday', $this->text($yesterday));
        self::assertSame('Since 3 days ago', $this->text($earlier));
        self::assertSame('Depuis hier', $this->text($french));
        self::assertCount(0, $this->panel('en')->filter('[data-fallback-days]'));
    }

    public function testTodayBeforeTheFirstReadingOfTheDaySaysSoInsteadOfShowingFigures(): void
    {
        $english = $this->panel('en', todayAwaitingReading: true);
        $french = $this->panel('fr', todayAwaitingReading: true);

        $row = $english->filter('[data-period=today]');
        self::assertSame('Today', $this->text($row->filter('td')->first()->filter('span')->first()));
        self::assertSame('No reading yet today', $this->text($row->filter('[data-awaiting-reading]')));
        self::assertCount(0, $row->filter('[data-figure]'));
        self::assertCount(0, $row->filter('[data-no-history]'));
        self::assertSame('Pas encore de relevé aujourd’hui', $this->text($french->filter('[data-awaiting-reading]')));
        self::assertSame('+3 km', $this->text($english->filter('[data-period=two_weeks] [data-figure=delta]')));
    }

    public function testYesterdayHasItsOwnRowInBothLanguages(): void
    {
        $english = $this->panel('en')->filter('[data-period=yesterday]');
        $french = $this->panel('fr')->filter('[data-period=yesterday]');

        self::assertSame('Yesterday', $this->text($english->filter('td')->first()->filter('span')->first()));
        self::assertSame('44 / 45 km', $this->text($english->filter('[data-figure=figures]')));
        self::assertSame('Hier', $this->text($french->filter('td')->first()->filter('span')->first()));
    }

    public function testTheFrenchPanelUsesFrenchWordsAndASwissPercentage(): void
    {
        $panel = $this->panel('fr');

        $period = $panel->filter('[data-period=today] td')->first()->filter('span')->first();

        self::assertSame('Aujourd’hui', $this->text($period));
        self::assertSame('+11%', $this->text($panel->filter('[data-period=today] [data-figure=ratio]')));
    }

    protected function tearDown(): void
    {
        \Locale::setDefault('en');
        parent::tearDown();
    }

    public function testAnInfoButtonOpensTheExplanationOfThePanel(): void
    {
        $panel = $this->panel('en', help: 'compare_budget');

        $button = $panel->filter('[data-help=compare-budget]');
        self::assertCount(1, $button);
        self::assertSame('About “Title”', $button->attr('aria-label'));
        self::assertSame(
            'Did you stay within your mileage budget?',
            $this->text($panel->filter('dialog [data-help-answer]')),
        );
    }

    public function testTheExplanationHasAnExampleRowTheGapLegendAndOneCardPerTopic(): void
    {
        $dialog = $this->panel('en', help: 'compare_budget')->filter('dialog');

        // The example row is 320 / 280 km, 40 km over, 14 %.
        self::assertStringContainsString('320', $dialog->filter('[data-help-anatomy]')->text());
        self::assertStringContainsString('+40', $dialog->filter('[data-help-anatomy]')->text());
        self::assertStringContainsString('+14%', $dialog->filter('[data-help-anatomy]')->text());
        self::assertCount(1, $dialog->filter('[data-help-gap]'));
        // Periods, reference, how today is counted, the live badge, missing history.
        self::assertCount(5, $dialog->filter('[data-help-section]'));
        self::assertStringContainsString('Driving hours', $dialog->filter('[data-help-details]')->text());
    }

    public function testTheProjectedEndExplanationDoesNotTalkAboutTheNight(): void
    {
        $dialog = $this->panel('en', help: 'compare_projected_end')->filter('dialog');

        self::assertCount(4, $dialog->filter('[data-help-section]'));
        self::assertStringNotContainsString('Driving hours', $dialog->filter('[data-help-details]')->text());
    }

    public function testTheExplanationIsTranslated(): void
    {
        $dialog = $this->panel('fr', help: 'compare_pace')->filter('dialog');

        self::assertSame('Roulez-vous plus ou moins que d’habitude?', $this->text($dialog->filter('[data-help-answer]')));
    }

    public function testWithoutAnExplanationThereIsNoInfoButton(): void
    {
        self::assertCount(0, $this->panel('en')->filter('[data-help]'));
    }

    private function panel(
        string $locale,
        bool $withHistory = true,
        ?string $help = null,
        int $todayFallbackDays = 0,
        bool $todayAwaitingReading = false,
        bool $recalculating = false,
    ): Crawler {
        self::bootKernel();
        $translator = self::getContainer()->get('translator');
        $translator->setLocale($locale);
        // The number formats of Twig follow the default locale of PHP, which a request sets in the application.
        \Locale::setDefault($locale);
        $rows = [
            new ComparisonRow(
                ComparisonPeriod::Today,
                $todayAwaitingReading ? null : $this->figures(50_000, 45_000),
                $todayFallbackDays,
                awaitingReading: $todayAwaitingReading,
            ),
            new ComparisonRow(ComparisonPeriod::Yesterday, $withHistory ? $this->figures(44_000, 45_000) : null),
            new ComparisonRow(ComparisonPeriod::LastWeek, $withHistory ? $this->figures(38_000, 40_000) : null),
            new ComparisonRow(ComparisonPeriod::LastTwoWeeks, $withHistory ? $this->figures(3_000, 0) : null),
            new ComparisonRow(ComparisonPeriod::LastMonth, $withHistory ? $this->figures(9_000, 9_000) : null),
        ];

        $template = "{{ component('Dashboard:ComparisonPanel', {"
            . "key: 'budget', title: 'Title', basis: 'Basis', rows: rows, help: help, recalculating: recalculating"
            . '}) }}';
        $html = self::getContainer()->get(Environment::class)
            ->createTemplate($template)
            ->render(['rows' => $rows, 'help' => $help, 'recalculating' => $recalculating]);

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
