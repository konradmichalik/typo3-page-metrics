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
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{MetricTile, PageMetricsViewData};
use KonradMichalik\Typo3PageMetrics\Service\TileStatsCalculator;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\View\{ViewFactoryData, ViewFactoryInterface};
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function array_map;
use function substr_count;

/**
 * ModulePageTemplateTest.
 *
 * The page view rendered for real, for the one thing it says that the tile
 * row cannot: how much of a figure arrived on a record below the page rather
 * than on the page itself. That is a sentence, and a sentence is markup.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ModulePageTemplateTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/../Service/Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    public function testNamesBothFiguresRatherThanTheShareBetweenThem(): void
    {
        $html = $this->render();

        // "830 of these" and not "67%": the question is how much of the
        // number is the page itself, and the module has the room to answer
        // it with the figure rather than with the mechanism that produced it.
        self::assertStringContainsString('1240', $html);
        self::assertStringContainsString('830', $html);
        self::assertStringContainsString('on records below this page', $html);
    }

    public function testSaysItInWordsRatherThanBehindAHover(): void
    {
        $html = $this->render();

        // The module exists because there is room to explain; putting this
        // back into a tooltip would be the tile's constraint imported into
        // the surface that does not have it.
        self::assertStringNotContainsString('data-hint-title="of which', $html);
    }

    public function testAMetricWithNoRecordsBelowItSaysNothingAtAll(): void
    {
        $html = $this->render();

        // Visits has the same rows as page views minus the dimension, which
        // is what almost every page and almost every metric looks like. One
        // occurrence, from the page-views row alone.
        self::assertSame(1, substr_count($html, 'on records below this page'));
        self::assertStringNotContainsString('of which 0 ', $html);
    }

    private function render(): string
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
        $viewData = $this->viewData();
        $calculator = $this->get(TileStatsCalculator::class);

        $view->assignMultiple([
            'pageId' => 2,
            'period' => 30,
            'activeView' => ModuleView::Page,
            'viewData' => $viewData,
            // The same pairing the controller builds: a metric identifier
            // contains a dot, which Fluid reads as a step into a nested
            // array, so the stats travel beside their tile rather than in a
            // map the template looks into.
            'tileBlocks' => array_map(
                static fn (MetricTile $tile): array => ['tile' => $tile, 'stats' => $calculator->forTile($tile)],
                $viewData->tiles,
            ),
            'chartSeries' => [],
            'sparklineLabels' => [],
            'annotations' => [],
        ]);

        return $view->render('Module/Page');
    }

    private function viewData(): PageMetricsViewData
    {
        $tiles = [
            new MetricTile('matomo.pageviews', 'Page views', 'How often the page was viewed.', 'tabler-eye-search', '#0969da', MetricUnit::Count, 1240, '1240', [], 12, TrendDirection::Up, 0, null, '1100', MetricPolarity::HigherIsBetter, [], null, true, 1100, '830'),
            new MetricTile('matomo.visits', 'Visits', 'How many visits included the page.', 'tabler-chart-dots', '#8250df', MetricUnit::Count, 900, '900', [], 4, TrendDirection::Up, 0, null, '860', MetricPolarity::HigherIsBetter, [], null, true, 860),
        ];

        return new PageMetricsViewData(true, 2, $tiles, 0, 30, [7, 30, 90], '2026-08-01 to 2026-08-30', '2026-07-02 to 2026-07-31', [], false, false, null, null);
    }
}
