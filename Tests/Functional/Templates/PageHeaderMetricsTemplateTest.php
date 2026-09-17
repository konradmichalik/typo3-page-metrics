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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricPolarity, MetricUnit, TrendDirection};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\MetricTile;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\View\{ViewFactoryData, ViewFactoryInterface};
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * PageHeaderMetricsTemplateTest.
 *
 * The tile row's markup, which is the whole of what the server renders for
 * the page module: everything a reader sees inside a tile popover is built
 * by sparkline.js from the data attributes asserted here.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class PageHeaderMetricsTemplateTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/../Service/Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    public function testHandsThePopoverTheFigureThatArrivedOnRecordsBelowThePage(): void
    {
        $html = $this->render();

        // The figure, not a second percentage beside the redirect share: the
        // popover already carries one share, and the fact an editor is after
        // is how much of the number below it is the page itself.
        self::assertStringContainsString('data-record-value="830"', $html);
    }

    public function testATileWithNoRecordsBelowItHandsOverNothing(): void
    {
        $html = $this->render();

        // Empty rather than "0", which is what keeps the chip out of the
        // popover of the overwhelming majority of pages.
        self::assertStringContainsString('data-record-value=""', $html);
    }

    public function testHandsThePopoverTheNameOfTheSourceTheNumbersCameFrom(): void
    {
        $html = $this->render();

        // What the popover turns into either "Open in Matomo" on the link or,
        // on a tile without one, a marker of its own.
        self::assertStringContainsString('data-source="Matomo"', $html);
    }

    public function testATileWithoutAKnownSourceHandsOverNothing(): void
    {
        // A tile contributed through ModifyMetricTilesEvent knows no
        // provider. Empty, so the heading stays as bare as it was rather
        // than claiming a source.
        self::assertStringContainsString('data-source=""', $this->render());
    }

    private function render(): string
    {
        $request = (new ServerRequest('https://example.com/typo3/ajax/page-metrics/tiles', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            ['EXT:typo3_page_metrics/Resources/Private/Templates/'],
            ['EXT:typo3_page_metrics/Resources/Private/Partials/'],
            ['EXT:typo3_page_metrics/Resources/Private/Layouts/'],
            null,
            $request,
        ));
        $view->assignMultiple([
            'sparklineLabels' => [],
            'pageId' => 2,
            'tiles' => $this->tiles(),
            'unresolvedCount' => 0,
            'periodDays' => 30,
            'periodOptions' => [7, 30, 90],
            'currentRangeLabel' => '2026-08-01 to 2026-08-30',
            'previousRangeLabel' => '2026-07-02 to 2026-07-31',
            'includeSubpages' => false,
            'hasSubpages' => false,
            'dataFreshness' => null,
            'addAnnotationUrl' => null,
            'annotations' => [],
        ]);

        return $view->render('PageHeader/Metrics');
    }

    /**
     * @return list<MetricTile>
     */
    private function tiles(): array
    {
        return [
            new MetricTile('matomo.pageviews', 'Page views', 'How often the page was viewed.', 'tabler-eye-search', '#0969da', MetricUnit::Count, 1240, '1240', [], 12, TrendDirection::Up, 0, null, '1100', MetricPolarity::HigherIsBetter, [], null, true, 1100, '830', 'Matomo'),
            new MetricTile('matomo.visits', 'Visits', 'How many visits included the page.', 'tabler-chart-dots', '#8250df', MetricUnit::Count, 900, '900', [], 4, TrendDirection::Up, 0, null, '860', MetricPolarity::HigherIsBetter, [], null, true, 860),
            new MetricTile('listener.custom', 'Newsletter signups', 'Contributed by a listener.', 'tabler-target', '#1a7f37', MetricUnit::Count, 12, '12', [], null, TrendDirection::Flat, 0, null, ''),
        ];
    }
}
