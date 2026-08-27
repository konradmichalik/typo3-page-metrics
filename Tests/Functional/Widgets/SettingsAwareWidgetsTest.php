<?php

declare(strict_types=1);

/*
 * This file is part of the "page_metrics" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Widgets;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Persistence\{AnnotationRepository, DailyMetricsRepository};
use KonradMichalik\Typo3PageMetrics\Widgets\{PageMetricsChartWidget, TrendWidget};
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Settings\SettingsInterface;
use TYPO3\CMS\Dashboard\Factory\WidgetSettingsFactory;
use TYPO3\CMS\Dashboard\Widgets\{WidgetConfigurationInterface, WidgetContext, WidgetResult};
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * SettingsAwareWidgetsTest.
 *
 * Covers the chart and trend widgets, both implementing
 * WidgetRendererInterface for a per-instance settings UI - a different
 * rendering contract than the other three site-metrics widgets (see
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
        parent::setUp();
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

    public function testTrendWidgetOffersDirectionAndPeriodSettings(): void
    {
        $widget = $this->get('dashboard.widget.pageMetricsTrend');
        self::assertInstanceOf(TrendWidget::class, $widget);

        $keys = array_map(static fn ($definition) => $definition->key, $widget->getSettingsDefinitions());
        self::assertSame(['direction', 'period'], $keys);
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
