<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_page_metrics" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Templates;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricPolarity, MetricUnit, ModuleView, TrendDirection};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{SitePagesCell, SitePagesColumn, SitePagesRow, SitePagesTable};
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\View\{ViewFactoryData, ViewFactoryInterface};
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function substr_count;

/**
 * ModulePagesTemplateTest.
 *
 * The pages view rendered for real, because what this view has to get right
 * is markup rather than data: aria-sort on exactly the one sorted column, a
 * sortable header that is a real link and not a clickable cell, a missing
 * value stated in words rather than left blank, and a trend that survives
 * being read without colour.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ModulePagesTemplateTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/../Service/Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    public function testMarksOnlyTheSortedColumnWithAriaSort(): void
    {
        $html = $this->render();

        self::assertSame(1, substr_count($html, 'aria-sort="descending"'));
        // The bounce rate column and the decay column, neither of which
        // holds the order.
        self::assertSame(2, substr_count($html, 'aria-sort="none"'));
    }

    public function testDecaySortsFromAHeaderLinkLikeEveryMetricColumn(): void
    {
        $html = $this->render();

        self::assertStringContainsString('sort=decay&amp;direction=desc', $html);
    }

    public function testNamesTheDecayVerdictInWordsAndNotOnlyInColour(): void
    {
        $html = $this->render();

        self::assertStringContainsString('Decaying', $html);
        self::assertStringContainsString('unchanged for 412 days', $html);
        // The page somebody edited last week carries the age without the
        // word, so the verdict is readable as a word and not as a shade.
        self::assertStringContainsString('unchanged for 3 days', $html);
    }

    public function testSaysInWordsWhenNobodyRecordedWhenAPageLastChanged(): void
    {
        $html = $this->render();

        self::assertStringContainsString('no record of when this page last changed', $html);
    }

    public function testExplainsWhatDecayMeansInProseRatherThanInATooltip(): void
    {
        $html = $this->render();

        self::assertStringContainsString('A page is decaying when it lost', $html);
        self::assertStringContainsString('it was never found', $html);
        self::assertStringNotContainsString('data-hint-title', $html);
    }

    public function testEverySortableColumnIsALinkCarryingTheOrderItWouldAskFor(): void
    {
        $html = $this->render();

        // The sorted column offers the other direction; an unsorted one
        // opens at descending, because "most of it" is the question a metric
        // column is opened with.
        self::assertStringContainsString('sort=matomo.pageviews&amp;direction=asc', $html);
        self::assertStringContainsString('sort=matomo.bounce_rate&amp;direction=desc', $html);
    }

    public function testUsesRealTableSemanticsForTheRowHeader(): void
    {
        $html = $this->render();

        self::assertStringContainsString('<th scope="row">', $html);
        self::assertStringContainsString('About', $html);
    }

    public function testStatesAMissingRateInWordsRatherThanLeavingTheCellBlank(): void
    {
        $html = $this->render();

        self::assertStringContainsString('not recorded', $html);
    }

    public function testSpellsTheTrendOutBesideItsColour(): void
    {
        $html = $this->render();

        self::assertStringContainsString('pagemetrics-trend-positive', $html);
        // The written movement the shared TrendBadge partial carries, which
        // is what is left in a forced-colours mode and in a screen reader.
        self::assertStringContainsString('Improved by', $html);
        self::assertStringContainsString('12%', $html);
    }

    public function testExplainsTheTableInProseRatherThanInATooltip(): void
    {
        $html = $this->render();

        self::assertStringContainsString('pagemetrics-module-intro', $html);
        self::assertStringContainsString('Pages you cannot see in the page tree are left out', $html);
        self::assertStringNotContainsString('data-hint-title', $html);
    }

    public function testDrawsTheSortedMetricsShapeInItsOwnColumnInsteadOfATrendColumn(): void
    {
        $html = $this->render();

        // The page, the two metrics and decay. The trend column is gone: the
        // shape and the change it used to hold now sit in the column of the
        // metric they are about.
        self::assertSame(4, substr_count($html, '<th scope="col"'));
        self::assertStringContainsString('pagemetrics-widget-chart', $html);
        self::assertStringContainsString('data-sparkline=', $html);
    }

    public function testKeepsTheChangeInTheSameCellAsTheShape(): void
    {
        $html = $this->render();

        // A shape says "falling" and only a number says how far, so the
        // percentage stays. In the same cell, because a column of its own is
        // exactly what this change removed.
        self::assertMatchesRegularExpression('/data-sparkline=.{0,4000}?pagemetrics-trend.*?<\/td>/s', $html);
    }

    public function testDescribesEachColumnOnceAboveTheTableRatherThanInEveryHeader(): void
    {
        $html = $this->render();

        // Prose, still, and read rather than hovered. Once, beside the table,
        // instead of once per column header, which is what made the table
        // wider than the screen.
        self::assertSame(1, substr_count($html, 'How often the page was viewed.'));
        self::assertStringContainsString('pagemetrics-pages-glossary', $html);
        self::assertLessThan(strpos($html, '<table'), strpos($html, 'How often the page was viewed.'));
        self::assertStringNotContainsString('data-hint-title', $html);
    }

    public function testLeavesTheShapeOutOfTheAccessibleTreeBecauseTheCellSaysItInWords(): void
    {
        $html = $this->render();

        self::assertMatchesRegularExpression('/<div[^>]*class="[^"]*pagemetrics-widget-chart[^"]*"[^>]*aria-hidden="true"/', $html);
    }

    public function testSaysWhichPartOfTheWholeIsOnScreen(): void
    {
        $html = $this->render();

        self::assertStringContainsString('Showing 1 to 3 of 31 pages.', $html);
        self::assertStringContainsString('tablePage=2', $html);
    }

    public function testDrawsThePlaceholderWhileTheControllerAssignsNoTable(): void
    {
        $html = $this->render(false);

        self::assertStringContainsString('This view is being built.', $html);
        self::assertStringNotContainsString('<table', $html);
    }

    public function testSaysWhyThereIsNoTableForAPageOutsideEverySite(): void
    {
        // Two different answers behind one null table. A page belonging to no
        // configured site is a fact the reader can act on; telling them the
        // feature is unfinished instead sends them to look for a release note
        // that will never mention it.
        $html = $this->render(false, false);

        self::assertStringContainsString('does not belong to a configured site', $html);
        self::assertStringNotContainsString('This view is being built.', $html);
    }

    private function render(bool $withTable = true, bool $hasSite = true): string
    {
        $request = (new ServerRequest('https://example.com/typo3/module/web/pagemetrics', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            ['EXT:typo3_page_metrics/Resources/Private/Templates/'],
            ['EXT:typo3_page_metrics/Resources/Private/Partials/'],
            [__DIR__.'/Fixtures/'],
            null,
            $request,
        ));
        $view->assignMultiple([
            'pageId' => 2,
            'period' => 30,
            'activeView' => ModuleView::Pages,
            'pagesTable' => $withTable ? $this->table() : null,
            'hasSite' => $hasSite,
        ]);

        return $view->render('Module/Pages');
    }

    private function table(): SitePagesTable
    {
        $columns = [
            new SitePagesColumn('matomo.pageviews', 'Page views', 'How often the page was viewed.', true, SitePagesTable::DIRECTION_DESCENDING, MetricUnit::Count),
            new SitePagesColumn('matomo.bounce_rate', 'Bounce rate', 'The share of visits that left again.', false, SitePagesTable::DIRECTION_DESCENDING, MetricUnit::Percent),
        ];

        $rows = [
            new SitePagesRow(
                2,
                'About',
                [new SitePagesCell(true, '42', true, $this->series()), new SitePagesCell(false, '', false, null)],
                12,
                TrendDirection::Up,
                MetricPolarity::HigherIsBetter,
                true,
                412,
                true,
            ),
            new SitePagesRow(
                3,
                'Contact',
                [new SitePagesCell(true, '7', true, $this->series()), new SitePagesCell(false, '', false, null)],
                -4,
                TrendDirection::Down,
                MetricPolarity::HigherIsBetter,
                true,
                3,
                false,
            ),
            // No record of when this one last changed, which is not an age of
            // zero and has to read differently from one.
            new SitePagesRow(
                4,
                'Imprint',
                // Nothing was recorded for this one in the period, so there
                // is no shape to draw and the cell still carries the change.
                [new SitePagesCell(true, '1', true, null), new SitePagesCell(false, '', false, null)],
                0,
                TrendDirection::Flat,
                MetricPolarity::HigherIsBetter,
                true,
                null,
                false,
            ),
        ];

        return new SitePagesTable(
            true,
            $columns,
            $rows,
            'matomo.pageviews',
            SitePagesTable::DIRECTION_DESCENDING,
            'Page views',
            '2026-09-07 to 2026-09-13',
            '2026-08-31 to 2026-09-06',
            31,
            1,
            true,
            SitePagesColumn::forDecay(false, SitePagesTable::DIRECTION_DESCENDING),
        );
    }

    /**
     * @return array{label: string, color: string, unit: string, values: array<string, int|null>}
     */
    private function series(): array
    {
        // The last of the three days was never imported, which the renderer
        // has to leave blank rather than draw at zero.
        return [
            'label' => 'Page views',
            'color' => '#1a73e8',
            'unit' => 'count',
            'values' => ['2026-09-11' => 4, '2026-09-12' => 9, '2026-09-13' => null],
        ];
    }
}
