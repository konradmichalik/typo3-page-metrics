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
use KonradMichalik\Typo3PageMetrics\Persistence\{BotAgentDailyRepository, DailyMetricsRepository};
use KonradMichalik\Typo3PageMetrics\Widgets\PageMetricsBreakdownWidget;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Settings\{SettingDefinition, SettingsInterface};
use TYPO3\CMS\Dashboard\Factory\WidgetSettingsFactory;
use TYPO3\CMS\Dashboard\Widgets\{WidgetConfigurationInterface, WidgetContext, WidgetRendererInterface, WidgetResult};
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function interface_exists;

/**
 * PageMetricsBreakdownWidgetTest.
 *
 * PageMetricsBreakdownWidget implements WidgetRendererInterface for its
 * Metric and Period settings, a TYPO3 14 addition (see
 * Configuration/Services.php) - on TYPO3 13.4 the widget is not registered
 * at all, so there is nothing to fetch from the container.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class PageMetricsBreakdownWidgetTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    protected function setUp(): void
    {
        // Skipping has to happen after parent::setUp(), not before: the
        // testing framework's tearDown() reads $instancePath, which only the
        // parent initialises, and would fatal on a test skipped ahead of it.
        parent::setUp();

        if (!interface_exists(WidgetRendererInterface::class)) {
            self::markTestSkipped('PageMetricsBreakdownWidget requires TYPO3 14 (WidgetRendererInterface).');
        }

        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    public function testIsRegisteredAsADashboardWidgetOnlyBecauseExtDashboardIsLoaded(): void
    {
        self::assertInstanceOf(PageMetricsBreakdownWidget::class, $this->get('dashboard.widget.pageMetricsBreakdown'));
    }

    public function testRendersTheDefaultMetricsHeadlineAndStats(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 42);

        $content = $this->renderWidget([]);

        self::assertStringContainsString('pagemetrics-widget-metric-headline-value', $content);
        self::assertStringContainsString('42', $content);
        self::assertStringContainsString('pagemetrics-widget-metric-stats', $content);
        self::assertStringContainsString('Last day', $content);
        self::assertStringContainsString('Peak', $content);
    }

    public function testEachStatChipCarriesAnIconAndAHoverTooltip(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 42);

        $content = $this->renderWidget([]);

        self::assertStringContainsString('pagemetrics-widget-metric-stat-icon', $content);
        self::assertStringContainsString('<svg', $content);
        self::assertStringContainsString('data-hint-title="Last day"', $content);
        self::assertStringContainsString('data-hint-text="The most recent day of the selected period"', $content);
    }

    public function testRendersTheTilesOwnCurveAsACompactChart(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 42);

        $content = $this->renderWidget([]);

        self::assertStringContainsString('pagemetrics-widget-chart-compact', $content);
        self::assertStringContainsString('data-sparkline="', $content);

        preg_match('/data-sparkline="([^"]*)"/', $content, $matches);
        $series = json_decode(html_entity_decode($matches[1] ?? ''), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(1, $series);
        self::assertSame('Page views', $series[0]['label']);
    }

    public function testLoadsTheSparklineJavaScriptModule(): void
    {
        $widget = $this->get('dashboard.widget.pageMetricsBreakdown');
        self::assertInstanceOf(PageMetricsBreakdownWidget::class, $widget);

        $modules = $widget->getJavaScriptModuleInstructions();

        self::assertCount(1, $modules);
        self::assertInstanceOf(JavaScriptModuleInstruction::class, $modules[0]);
    }

    public function testRendersTheEmptyStateWhenNoSiteIsConfigured(): void
    {
        self::assertStringContainsString('callout-info', $this->renderWidget([]));
    }

    public function testNamesEachSiteAndItsResolvedMetricWhenMoreThanOneSiteIsConfigured(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $this->writeSiteConfiguration('second', 2, 'https://second.example.com/');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 42);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 7);

        $content = $this->renderWidget([]);

        self::assertStringContainsString('pagemetrics-widget-site-title', $content);
        self::assertStringContainsString('main', $content);
        self::assertStringContainsString('second', $content);
        self::assertStringContainsString('Page views', $content);
    }

    public function testRendersTheChosenMetricInsteadOfTheSitesPrimaryOne(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.pageviews', 42);
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.visits', 7);

        $content = $this->renderWidget(['metric' => 'matomo.visits']);

        self::assertStringContainsString('7', $content);
        self::assertStringNotContainsString('42', $content);
    }

    public function testFallsBackToTheSitesPrimaryMetricWhenTheConfiguredOneIsUnsupported(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 42);

        self::assertStringContainsString('42', $this->renderWidget(['metric' => 'ga4.sessions']));
    }

    public function testRendersABreakdownForTheChosenMetricWhenItHasOne(): void
    {
        $this->writeSiteConfigurationWithAccessLog();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'accesslog', $today, 'bot.ai_crawler', 30);
        $agents = $this->get(BotAgentDailyRepository::class);
        $agents->upsert('main', 1, 0, 'ai_crawler', 'GPTBot', $today, 20, 0);
        $agents->upsert('main', 1, 0, 'ai_crawler', 'ClaudeBot', $today, 10, 0);

        $content = $this->renderWidget(['metric' => 'bot.ai_crawler']);

        self::assertStringContainsString('pagemetrics-widget-metric-breakdown', $content);
        self::assertStringContainsString('GPTBot', $content);
        self::assertStringContainsString('ClaudeBot', $content);
        // Every part carries its own $value (see TileBreakdownBuilder), so
        // DonutChartBuilder can size a slice for each of them.
        self::assertStringContainsString('pagemetrics-widget-donut', $content);
        self::assertStringContainsString('conic-gradient(', $content);
        self::assertStringContainsString('pagemetrics-widget-donut-legend-swatch', $content);
    }

    public function testRendersNoBreakdownForAMetricThatHasNone(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 42);

        self::assertStringNotContainsString('pagemetrics-widget-metric-breakdown', $this->renderWidget(['metric' => 'matomo.pageviews']));
    }

    public function testDeclaresItsBackendCssAssetAndOffersMetricAndPeriodSettings(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $widget = $this->get('dashboard.widget.pageMetricsBreakdown');
        self::assertInstanceOf(PageMetricsBreakdownWidget::class, $widget);

        self::assertSame(['EXT:typo3_page_metrics/Resources/Public/Css/page-metrics.css'], $widget->getCssFiles());

        $definitions = $widget->getSettingsDefinitions();
        self::assertCount(2, $definitions);

        self::assertSame('metric', $definitions[0]->key);
        self::assertSame('string', $definitions[0]->type);
        self::assertSame('', $definitions[0]->default);
        self::assertArrayHasKey('', $definitions[0]->enum);
        self::assertArrayHasKey('matomo.pageviews', $definitions[0]->enum);

        self::assertSame('period', $definitions[1]->key);
        self::assertSame('int', $definitions[1]->type);
        self::assertSame(7, $definitions[1]->default);
        self::assertArrayHasKey(7, $definitions[1]->enum);
        self::assertArrayHasKey(30, $definitions[1]->enum);
        self::assertArrayHasKey(90, $definitions[1]->enum);
    }

    public function testSkipsSitesWhoseRootPageHasNoTiles(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $this->writeSiteConfiguration('ghost', 99, 'https://ghost.example.com/');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 42);

        $content = $this->renderWidget([]);

        self::assertStringContainsString('main', $content);
        self::assertStringNotContainsString('ghost', $content);
    }

    public function testRespectsTheConfiguredPeriod(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.pageviews', 42);
        // Ten days back, so only a period long enough to reach it adds it to
        // the tile's total - 142 only appears once both figures are summed.
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today->modify('-10 days'), 'matomo.pageviews', 100);

        self::assertStringNotContainsString('142', $this->renderWidget([]));
        self::assertStringContainsString('142', $this->renderWidget(['period' => 30]));
    }

    public function testFallsBackToTheDefaultPeriodWhenAnInvalidValueIsConfigured(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.pageviews', 42);
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today->modify('-10 days'), 'matomo.pageviews', 100);

        self::assertStringNotContainsString('142', $this->renderWidget(['period' => 14]));
    }

    public function testLabelIncludesTheConfiguredPeriod(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 42);

        $result = $this->renderWidgetResult(['period' => 30]);

        self::assertStringContainsString('Page metrics breakdown', (string) $result->label);
        self::assertStringContainsString('30', (string) $result->label);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function renderWidget(array $settings): string
    {
        return $this->renderWidgetResult($settings)->content;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function renderWidgetResult(array $settings): WidgetResult
    {
        $widget = $this->get('dashboard.widget.pageMetricsBreakdown');
        self::assertInstanceOf(PageMetricsBreakdownWidget::class, $widget);

        return $widget->renderWidget($this->buildContext($widget->getSettingsDefinitions(), $settings));
    }

    /**
     * @param array<int, SettingDefinition> $definitions
     * @param array<string, mixed>          $settings
     */
    private function buildContext(array $definitions, array $settings): WidgetContext
    {
        $configuration = $this->get('pageMetricsBreakdownWidgetConfiguration');
        self::assertInstanceOf(WidgetConfigurationInterface::class, $configuration);

        $request = (new ServerRequest('https://example.com/typo3/module/dashboard'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        $resolvedSettings = $this->get(WidgetSettingsFactory::class)->createSettings('pageMetricsBreakdown', $settings, $definitions);
        self::assertInstanceOf(SettingsInterface::class, $resolvedSettings);

        return new WidgetContext('pageMetricsBreakdown', [], $configuration, $resolvedSettings, $request);
    }

    private function writeSiteConfiguration(string $identifier, int $rootPageId, string $base): void
    {
        $this->get(SiteWriter::class)->write($identifier, [
            'rootPageId' => $rootPageId,
            'base' => $base,
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
            'pageMetrics' => ['providers' => ['matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 1, 'tokenAuth' => 'x']]],
        ]);
    }

    private function writeSiteConfigurationWithAccessLog(): void
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
            'pageMetrics' => ['providers' => ['accesslog' => ['pathPattern' => '/var/log/nginx/access.log']]],
        ]);
    }
}
