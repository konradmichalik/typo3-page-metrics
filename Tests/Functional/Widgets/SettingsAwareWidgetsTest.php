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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Widgets;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Persistence\{AnnotationRepository, DailyMetricsRepository};
use KonradMichalik\Typo3PageMetrics\Widgets\{PageMetricsChartWidget, TopPagesWidget, TrendWidget};
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Settings\SettingsInterface;
use TYPO3\CMS\Dashboard\Factory\WidgetSettingsFactory;
use TYPO3\CMS\Dashboard\Widgets\{WidgetConfigurationInterface, WidgetContext, WidgetRendererInterface, WidgetResult};
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function interface_exists;

/**
 * SettingsAwareWidgetsTest.
 *
 * Covers the chart, trend and top-pages widgets, all implementing
 * WidgetRendererInterface for a per-instance settings UI - a different
 * rendering contract than the other two site-metrics widgets (see
 * SiteMetricsWidgetsTest), so they need their own WidgetContext-based
 * render helper instead of setRequest()/renderWidgetContent().
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SettingsAwareWidgetsTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    protected function setUp(): void
    {
        // Skipping has to happen after parent::setUp(), not before: the
        // testing framework's tearDown() reads $instancePath, which only the
        // parent initialises, and would fatal on a test skipped ahead of it.
        parent::setUp();

        // The three widgets under test are registered only where
        // WidgetRendererInterface exists, which is a TYPO3 14 addition - on
        // TYPO3 13.4 Configuration/Services.php deliberately skips them, so
        // there is nothing to fetch from the container here.
        if (!interface_exists(WidgetRendererInterface::class)) {
            self::markTestSkipped('Settings-aware widgets require TYPO3 14 (WidgetRendererInterface).');
        }

        $this->importCSVDataSet(__DIR__.'/../Service/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__.'/../Service/Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    public function testChartWidgetOffersEveryRegisteredMetricAsASetting(): void
    {
        $this->writeSiteConfiguration();
        $widget = $this->get('dashboard.widget.pageMetricsChart');
        self::assertInstanceOf(PageMetricsChartWidget::class, $widget);

        $definitions = $widget->getSettingsDefinitions();
        self::assertCount(2, $definitions);
        self::assertSame('metric', $definitions[0]->key);
        self::assertArrayHasKey('matomo.pageviews', $definitions[0]->enum);
        self::assertArrayHasKey('matomo.bounce_rate', $definitions[0]->enum);
        self::assertSame('period', $definitions[1]->key);
        self::assertArrayHasKey(30, $definitions[1]->enum);
    }

    public function testChartWidgetMetricSettingOnlyOffersMetricsFromConfiguredSources(): void
    {
        $this->writeSiteConfiguration();
        $widget = $this->get('dashboard.widget.pageMetricsChart');
        self::assertInstanceOf(PageMetricsChartWidget::class, $widget);

        $enum = $widget->getSettingsDefinitions()[0]->enum;

        self::assertArrayNotHasKey('ga4.sessions', $enum);
        self::assertSame('Page views', $enum['matomo.pageviews']);
    }

    public function testChartWidgetDefaultsToTheSitesPrimaryMetric(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.pageviews', 42);
        $this->get(AnnotationRepository::class)->upsert('main', 'matomo', 'a1', $today, 'Release 2.0');

        $result = $this->renderChartWidgetResult([]);

        self::assertStringContainsString('Page views', (string) $result->label);
        self::assertStringContainsString('pagemetrics-widget-chart', $result->content);
        self::assertStringContainsString('data-sparkline="', $result->content);
        self::assertStringContainsString('&quot;Release 2.0&quot;', $result->content);
    }

    public function testChartWidgetPlotsTheConfiguredMetricInstead(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.pageviews', 42);
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.visits', 7);

        $result = $this->renderChartWidgetResult(['metric' => 'matomo.visits']);

        self::assertStringContainsString('Visits', (string) $result->label);
        self::assertStringNotContainsString('Page views', (string) $result->label);
    }

    public function testChartWidgetRespectsTheConfiguredPeriod(): void
    {
        $this->writeSiteConfiguration();
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 42);

        $content = $this->renderChartWidget(['period' => 7]);

        preg_match('/data-sparkline="([^"]*)"/', $content, $matches);
        $raw = $matches[1] ?? self::fail('data-sparkline attribute not found in widget content');
        $series = json_decode(html_entity_decode($raw), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(7, $series);
    }

    public function testChartWidgetCanPlotARateMetric(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.entry_bounces', 3);
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.entries', 4);

        $result = $this->renderChartWidgetResult(['metric' => 'matomo.bounce_rate']);

        self::assertStringContainsString('Bounce rate', (string) $result->label);
        self::assertStringContainsString('data-unit="percent"', $result->content);
    }

    public function testChartWidgetFallsBackToThePrimaryMetricWhenTheConfiguredOneIsUnknown(): void
    {
        $this->writeSiteConfiguration();
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 42);

        $result = $this->renderChartWidgetResult(['metric' => 'ga4.sessions']);

        self::assertStringContainsString('Page views', (string) $result->label);
    }

    public function testChartWidgetRendersTheEmptyStateWhenNoSiteIsConfigured(): void
    {
        self::assertStringContainsString('callout-info', $this->renderChartWidget([]));
    }

    public function testTrendWidgetOffersMetricDirectionAndPeriodSettings(): void
    {
        $this->writeSiteConfiguration();
        $widget = $this->get('dashboard.widget.pageMetricsTrend');
        self::assertInstanceOf(TrendWidget::class, $widget);

        $definitions = $widget->getSettingsDefinitions();
        $keys = array_map(static fn ($definition) => $definition->key, $definitions);
        self::assertSame(['metric', 'direction', 'period'], $keys);
        self::assertArrayHasKey('matomo.bounce_rate', $definitions[0]->enum);
    }

    public function testTrendWidgetMetricSettingOnlyOffersMetricsFromConfiguredSources(): void
    {
        $this->writeSiteConfiguration();
        $widget = $this->get('dashboard.widget.pageMetricsTrend');
        self::assertInstanceOf(TrendWidget::class, $widget);

        $enum = $widget->getSettingsDefinitions()[0]->enum;

        self::assertArrayNotHasKey('ga4.sessions', $enum);
        self::assertSame('Page views', $enum['matomo.pageviews']);
    }

    public function testTrendWidgetListsTheBiggestDropByDefault(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        // Page 2 dropped from 100 to 10, page 3 grew from 10 to 100 - only
        // the drop should show up when direction defaults to "down".
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 10);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-40 days'), 'matomo.pageviews', 100);
        $this->get(DailyMetricsRepository::class)->upsert(3, 0, 'matomo', $today, 'matomo.pageviews', 100);
        $this->get(DailyMetricsRepository::class)->upsert(3, 0, 'matomo', $today->modify('-40 days'), 'matomo.pageviews', 10);

        $content = $this->renderTrendWidget([]);

        self::assertStringContainsString('About', $content);
        self::assertStringContainsString('pagemetrics-trend-down', $content);
        self::assertStringNotContainsString('Restricted', $content);
        self::assertStringContainsString('pagemetrics-widget-trend-direction-down', $content);
    }

    public function testTrendWidgetListsTheBiggestGainWhenConfigured(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 10);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-40 days'), 'matomo.pageviews', 100);
        $this->get(DailyMetricsRepository::class)->upsert(3, 0, 'matomo', $today, 'matomo.pageviews', 100);
        $this->get(DailyMetricsRepository::class)->upsert(3, 0, 'matomo', $today->modify('-40 days'), 'matomo.pageviews', 10);

        $content = $this->renderTrendWidget(['direction' => 'up']);

        self::assertStringContainsString('Restricted', $content);
        self::assertStringContainsString('pagemetrics-trend-up', $content);
        self::assertStringNotContainsString('About', $content);
        self::assertStringContainsString('pagemetrics-widget-trend-direction-up', $content);
        self::assertStringNotContainsString('pagemetrics-widget-trend-direction-down', $content);
    }

    public function testTrendWidgetRendersTheEmptyStateWhenNoSiteIsConfigured(): void
    {
        $result = $this->renderTrendWidgetResult([]);

        self::assertStringContainsString('callout-info', $result->content);
        self::assertNull($result->label);
    }

    public function testTrendWidgetLabelIncludesTheConfiguredDirectionAndPeriod(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 10);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-40 days'), 'matomo.pageviews', 100);

        $result = $this->renderTrendWidgetResult(['direction' => 'up', 'period' => 7]);

        self::assertStringContainsString('Page metrics trend', (string) $result->label);
        self::assertStringContainsString('Biggest gains', (string) $result->label);
        self::assertStringContainsString('7 days', (string) $result->label);
    }

    public function testTrendWidgetRanksByTheConfiguredRateMetricInstead(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $previous = $today->modify('-40 days');
        // Page 2's bounce rate worsens from 20% to 80% - page views stay
        // flat, so this would only show up when ranking by bounce rate.
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.entry_bounces', 8);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.entries', 10);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $previous, 'matomo.entry_bounces', 2);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $previous, 'matomo.entries', 10);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 50);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $previous, 'matomo.pageviews', 50);

        $result = $this->renderTrendWidgetResult(['metric' => 'matomo.bounce_rate', 'direction' => 'up']);

        self::assertStringContainsString('About', $result->content);
        self::assertStringContainsString('80%', $result->content);
        self::assertStringContainsString('Bounce rate', (string) $result->label);
    }

    public function testTopPagesWidgetOffersALevelAndIncludeSubpagesSetting(): void
    {
        $widget = $this->get('dashboard.widget.pageMetricsTopPages');
        self::assertInstanceOf(TopPagesWidget::class, $widget);

        $definitions = $widget->getSettingsDefinitions();
        self::assertCount(2, $definitions);
        self::assertSame('level', $definitions[0]->key);
        self::assertArrayHasKey('', $definitions[0]->enum);
        self::assertArrayHasKey('1', $definitions[0]->enum);
        self::assertArrayHasKey('2', $definitions[0]->enum);
        self::assertArrayHasKey('3', $definitions[0]->enum);
        self::assertSame('includeSubpages', $definitions[1]->key);
        self::assertSame('bool', $definitions[1]->type);
        self::assertFalse($definitions[1]->default);
    }

    public function testTopPagesWidgetRanksAcrossTheWholeSiteByDefault(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 20);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-40 days'), 'matomo.pageviews', 10);
        // Page 5 ("Team") is a level-2 page (child of "Restricted") - still
        // included when no level restriction is configured.
        $this->get(DailyMetricsRepository::class)->upsert(5, 0, 'matomo', $today, 'matomo.pageviews', 5);

        $content = $this->renderTopPagesWidget([]);

        self::assertStringContainsString('About', $content);
        self::assertStringContainsString('Team', $content);
        self::assertStringContainsString('&amp;id=2', $content);
        self::assertStringContainsString('pagemetrics-trend-up', $content);
        self::assertStringContainsString('100%', $content);
    }

    public function testTopPagesWidgetRestrictsToTheConfiguredLevel(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 20);
        $this->get(DailyMetricsRepository::class)->upsert(5, 0, 'matomo', $today, 'matomo.pageviews', 8);

        $levelOne = $this->renderTopPagesWidget(['level' => '1']);
        $levelTwo = $this->renderTopPagesWidget(['level' => '2']);

        self::assertStringContainsString('About', $levelOne);
        self::assertStringNotContainsString('Team', $levelOne);
        self::assertStringContainsString('Team', $levelTwo);
        self::assertStringNotContainsString('About', $levelTwo);
    }

    public function testTopPagesWidgetLabelIncludesTheConfiguredLevel(): void
    {
        $this->writeSiteConfiguration();
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 20);

        $result = $this->renderTopPagesWidgetResult(['level' => '1']);

        self::assertStringContainsString('Most viewed pages', (string) $result->label);
        self::assertStringContainsString('Level 1', (string) $result->label);
    }

    public function testTopPagesWidgetRollsUpSubpagesIntoTheirLevelAncestorWhenConfigured(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        // Page 5 ("Team") is a child of page 3 ("Restricted") - a level-2
        // page rolling up into its level-1 ancestor.
        $this->get(DailyMetricsRepository::class)->upsert(3, 0, 'matomo', $today, 'matomo.pageviews', 10);
        $this->get(DailyMetricsRepository::class)->upsert(5, 0, 'matomo', $today, 'matomo.pageviews', 8);

        $withoutRollup = $this->renderTopPagesWidget(['level' => '1']);
        $withRollup = $this->renderTopPagesWidget(['level' => '1', 'includeSubpages' => '1']);

        self::assertMatchesRegularExpression('/\b10\b/', $withoutRollup);
        self::assertDoesNotMatchRegularExpression('/\b18\b/', $withoutRollup);
        self::assertMatchesRegularExpression('/\b18\b/', $withRollup);
        self::assertStringNotContainsString('Team', $withRollup);
    }

    public function testTopPagesWidgetLabelIncludesIncludeSubpagesOnlyWhenALevelIsSelected(): void
    {
        $this->writeSiteConfiguration();
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 20);

        $withLevel = $this->renderTopPagesWidgetResult(['level' => '1', 'includeSubpages' => '1']);
        $withoutLevel = $this->renderTopPagesWidgetResult(['includeSubpages' => '1']);

        self::assertStringContainsString('Include subpages', (string) $withLevel->label);
        self::assertStringNotContainsString('Include subpages', (string) $withoutLevel->label);
    }

    public function testTopPagesWidgetRendersTheEmptyStateWhenNoSiteIsConfigured(): void
    {
        $result = $this->renderTopPagesWidgetResult([]);

        self::assertStringContainsString('callout-info', $result->content);
        self::assertNull($result->label);
    }

    public function testEachSettingsAwareWidgetDeclaresItsBackendAssets(): void
    {
        $css = ['EXT:typo3_page_metrics/Resources/Public/Css/page-metrics.css'];

        $chart = $this->get('dashboard.widget.pageMetricsChart');
        self::assertInstanceOf(PageMetricsChartWidget::class, $chart);
        self::assertSame($css, $chart->getCssFiles());
        self::assertEquals(
            [JavaScriptModuleInstruction::create('@konradmichalik/page-metrics/sparkline.js')],
            $chart->getJavaScriptModuleInstructions(),
        );

        $trend = $this->get('dashboard.widget.pageMetricsTrend');
        self::assertInstanceOf(TrendWidget::class, $trend);
        self::assertSame($css, $trend->getCssFiles());

        $topPages = $this->get('dashboard.widget.pageMetricsTopPages');
        self::assertInstanceOf(TopPagesWidget::class, $topPages);
        self::assertSame($css, $topPages->getCssFiles());
    }

    public function testEachSettingsAwareWidgetSkipsSitesWithoutAConfiguredMetricSource(): void
    {
        $this->writeSiteConfiguration();
        $this->writeUnconfiguredSiteConfiguration();
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 42);

        foreach ([
            $this->renderChartWidget([]),
            $this->renderTrendWidget([]),
            $this->renderTopPagesWidget([]),
        ] as $content) {
            self::assertStringNotContainsString('unconfigured', $content);
        }
    }

    private function writeUnconfiguredSiteConfiguration(): void
    {
        $this->get(SiteWriter::class)->write('unconfigured', [
            'rootPageId' => 2,
            'base' => 'https://unconfigured.example.com/',
            'languages' => [
                0 => ['title' => 'English', 'enabled' => true, 'languageId' => 0, 'base' => '/', 'locale' => 'en_US.UTF-8', 'navigationTitle' => 'English', 'flag' => 'us'],
            ],
            'errorHandling' => [],
            'routes' => [],
        ]);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function renderChartWidget(array $settings): string
    {
        return $this->renderChartWidgetResult($settings)->content;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function renderChartWidgetResult(array $settings): WidgetResult
    {
        $widget = $this->get('dashboard.widget.pageMetricsChart');
        self::assertInstanceOf(PageMetricsChartWidget::class, $widget);

        return $widget->renderWidget($this->buildContext('pageMetricsChart', $widget->getSettingsDefinitions(), $settings));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function renderTrendWidget(array $settings): string
    {
        return $this->renderTrendWidgetResult($settings)->content;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function renderTrendWidgetResult(array $settings): WidgetResult
    {
        $widget = $this->get('dashboard.widget.pageMetricsTrend');
        self::assertInstanceOf(TrendWidget::class, $widget);

        return $widget->renderWidget($this->buildContext('pageMetricsTrend', $widget->getSettingsDefinitions(), $settings));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function renderTopPagesWidget(array $settings): string
    {
        return $this->renderTopPagesWidgetResult($settings)->content;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function renderTopPagesWidgetResult(array $settings): WidgetResult
    {
        $widget = $this->get('dashboard.widget.pageMetricsTopPages');
        self::assertInstanceOf(TopPagesWidget::class, $widget);

        return $widget->renderWidget($this->buildContext('pageMetricsTopPages', $widget->getSettingsDefinitions(), $settings));
    }

    /**
     * @param array<int, \TYPO3\CMS\Core\Settings\SettingDefinition> $definitions
     * @param array<string, mixed>                                   $settings
     */
    private function buildContext(string $identifier, array $definitions, array $settings): WidgetContext
    {
        $configuration = $this->get($identifier.'WidgetConfiguration');
        self::assertInstanceOf(WidgetConfigurationInterface::class, $configuration);

        $request = (new ServerRequest('https://example.com/typo3/module/dashboard'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        $resolvedSettings = $this->get(WidgetSettingsFactory::class)->createSettings($identifier, $settings, $definitions);
        self::assertInstanceOf(SettingsInterface::class, $resolvedSettings);

        return new WidgetContext($identifier, [], $configuration, $resolvedSettings, $request);
    }

    private function writeSiteConfiguration(): void
    {
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'languages' => [
                0 => [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'navigationTitle' => 'English',
                    'flag' => 'us',
                ],
            ],
            'errorHandling' => [],
            'routes' => [],
            'pageMetrics' => ['sources' => ['matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 1, 'tokenAuth' => 'x']]],
        ]);
    }
}
