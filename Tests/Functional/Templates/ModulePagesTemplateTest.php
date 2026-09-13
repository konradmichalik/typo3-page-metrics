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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricPolarity, ModuleView, TrendDirection};
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
        self::assertSame(1, substr_count($html, 'aria-sort="none"'));
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

    public function testSaysWhichPartOfTheWholeIsOnScreen(): void
    {
        $html = $this->render();

        self::assertStringContainsString('Showing 1 to 1 of 31 pages.', $html);
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
            new SitePagesColumn('matomo.pageviews', 'Page views', 'How often the page was viewed.', true, SitePagesTable::DIRECTION_DESCENDING),
            new SitePagesColumn('matomo.bounce_rate', 'Bounce rate', 'The share of visits that left again.', false, SitePagesTable::DIRECTION_DESCENDING),
        ];

        $rows = [
            new SitePagesRow(
                2,
                'About',
                [new SitePagesCell(true, '42'), new SitePagesCell(false, '')],
                12,
                TrendDirection::Up,
                MetricPolarity::HigherIsBetter,
                true,
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
        );
    }
}
