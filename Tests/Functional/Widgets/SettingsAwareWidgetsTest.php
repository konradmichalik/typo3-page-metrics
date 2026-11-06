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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\AnnotationCategory;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{Annotation, DateRange};
use KonradMichalik\Typo3PageMetrics\Persistence\{AnnotationRepository, DailyMetricsRepository, ImportStateRepository};
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

    public function testChartWidgetShowsTheDayItsNumbersAreFrom(): void
    {
        // The value travels through buildViewVariables()'s flat array, which
        // silently dropped it once: the overview carried it, the template
        // asked for it, and nothing in between mapped it across.
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today->modify('-1 day'), new DateRange($today->modify('-30 days'), $today), true);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 5);

        $content = $this->renderChartWidget([]);

        self::assertStringContainsString('pagemetrics-freshness', $content);
        self::assertStringContainsString($today->modify('-1 day')->format('Y-m-d'), $content);
    }

    public function testChartWidgetOffersEveryRegisteredMetricAsASetting(): void
    {
        $this->writeSiteConfiguration();
        $widget = $this->get('dashboard.widget.pageMetricsChart');
        self::assertInstanceOf(PageMetricsChartWidget::class, $widget);

        $definitions = $widget->getSettingsDefinitions();
        self::assertCount(3, $definitions);
        self::assertSame('metric', $definitions[0]->key);
        self::assertArrayHasKey('matomo.pageviews', $definitions[0]->enum);
        self::assertArrayHasKey('matomo.bounce_rate', $definitions[0]->enum);
        self::assertSame('period', $definitions[1]->key);
        self::assertArrayHasKey(30, $definitions[1]->enum);
        self::assertSame('annotationCategory', $definitions[2]->key);
        // The empty choice is every kind, so a chart configured before this
        // setting existed keeps showing what it showed.
        self::assertArrayHasKey('', $definitions[2]->enum);
        self::assertArrayHasKey('campaign', $definitions[2]->enum);
    }

    public function testChartWidgetMetricSettingOnlyOffersMetricsFromConfiguredProviders(): void
    {
        $this->writeSiteConfiguration();
        $widget = $this->get('dashboard.widget.pageMetricsChart');
        self::assertInstanceOf(PageMetricsChartWidget::class, $widget);

        $enum = $widget->getSettingsDefinitions()[0]->enum;

        self::assertArrayNotHasKey('ga4.sessions', $enum);
        self::assertSame('Page views', $enum['matomo.pageviews']);
    }

    public function testChartWidgetOffersAndPlotsTheMachineTrafficSummary(): void
    {
        $this->writeSiteConfigurationWithAccessLog();
        $widget = $this->get('dashboard.widget.pageMetricsChart');
        self::assertInstanceOf(PageMetricsChartWidget::class, $widget);

        $enum = $widget->getSettingsDefinitions()[0]->enum;
        self::assertArrayHasKey('machine.summary.accesslog', $enum);
        self::assertSame('Bot traffic', $enum['machine.summary.accesslog']);

        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'accesslog', $today, 'bot.ai_crawler', 40);
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'accesslog', $today, 'bot.search', 10);

        $result = $this->renderChartWidgetResult(['metric' => 'machine.summary.accesslog']);

        self::assertStringContainsString('Bot traffic', (string) $result->label);
    }

    public function testChartWidgetDefaultsToTheSitesPrimaryMetric(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.pageviews', 42);
        $this->get(AnnotationRepository::class)->upsert('main', 'matomo', new Annotation('a1', $today, 'Release 2.0'));

        $result = $this->renderChartWidgetResult([]);

        self::assertStringContainsString('Page views', (string) $result->label);
        self::assertStringContainsString('pagemetrics-widget-chart', $result->content);
        self::assertStringContainsString('data-sparkline="', $result->content);
        self::assertStringContainsString('&quot;Release 2.0&quot;', $result->content);
    }

    public function testChartWidgetDrawsOneMarkerForADaysDerivedPageMarkers(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.pageviews', 42);
        $this->seedDerivedPageMarkers($today);

        $annotations = $this->annotationsOf($this->renderChartWidget([]));

        self::assertSame(['3 pages updated (About, Home, Team)'], array_column($annotations, 'text'));
    }

    public function testTheMarkerCategorySettingStillPicksOneKindOutOfTheCollapsedOnes(): void
    {
        // The setting narrows by kind and collapsing merges within a kind,
        // so the two have to compose: filtering to "content" keeps the one
        // collapsed content marker and drops the release, and filtering to
        // "release" leaves the release alone.
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.pageviews', 42);
        $this->seedDerivedPageMarkers($today);
        $this->get(AnnotationRepository::class)->upsert('main', 'matomo', new Annotation('a1', $today, 'Release 2.0'));

        $content = $this->annotationsOf($this->renderChartWidget(['annotationCategory' => 'content']));
        $releases = $this->annotationsOf($this->renderChartWidget(['annotationCategory' => 'release']));

        self::assertSame(['3 pages updated (About, Home, Team)'], array_column($content, 'text'));
        self::assertSame(['Release 2.0'], array_column($releases, 'text'));
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

    public function testChartWidgetPlotsAConfiguredRateMetricInstead(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.pageviews', 42);
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.entry_bounces', 3);
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.entries', 4);

        $result = $this->renderChartWidgetResult(['metric' => 'matomo.bounce_rate']);

        self::assertStringContainsString('Bounce rate', (string) $result->label);
        self::assertStringNotContainsString('Page views', (string) $result->label);

        preg_match('/data-sparkline="([^"]*)"/', $result->content, $matches);
        $series = json_decode(html_entity_decode($matches[1] ?? ''), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('Bounce rate', $series[0]['label']);
        self::assertSame(75, $series[0]['values'][$today->format('Y-m-d')]);
    }

    /**
     * Nothing to assert on server-rendered markup beyond the shape itself -
     * the chart always emits exactly one series now that there is no second
     * metric to overlay.
     */
    public function testChartWidgetEmitsExactlyOneSeries(): void
    {
        $this->writeSiteConfiguration();
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 42);

        $content = $this->renderChartWidget([]);

        preg_match('/data-sparkline="([^"]*)"/', $content, $matches);
        $series = json_decode(html_entity_decode($matches[1] ?? ''), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(1, $series);
        self::assertSame('Page views', $series[0]['label']);
    }

    public function testChartWidgetRespectsTheConfiguredPeriod(): void
    {
        $this->writeSiteConfiguration();
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 42);

        $content = $this->renderChartWidget(['period' => 7]);

        preg_match('/data-sparkline="([^"]*)"/', $content, $matches);
        $raw = $matches[1] ?? self::fail('data-sparkline attribute not found in widget content');
        $series = json_decode(html_entity_decode($raw), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(7, $series[0]['values']);
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
        self::assertSame(['metric', 'sentiment', 'period'], $keys);
        self::assertArrayHasKey('matomo.bounce_rate', $definitions[0]->enum);
    }

    public function testTrendWidgetMetricSettingOnlyOffersMetricsFromConfiguredProviders(): void
    {
        $this->writeSiteConfiguration();
        $widget = $this->get('dashboard.widget.pageMetricsTrend');
        self::assertInstanceOf(TrendWidget::class, $widget);

        $enum = $widget->getSettingsDefinitions()[0]->enum;

        self::assertArrayNotHasKey('ga4.sessions', $enum);
        self::assertSame('Page views', $enum['matomo.pageviews']);
    }

    public function testTrendWidgetOffersSingleBotMetricsButNotTheirSummaryItCannotRank(): void
    {
        $this->writeSiteConfigurationWithAccessLog();
        $widget = $this->get('dashboard.widget.pageMetricsTrend');
        self::assertInstanceOf(TrendWidget::class, $widget);

        $enum = $widget->getSettingsDefinitions()[0]->enum;

        self::assertArrayHasKey('bot.search', $enum);
        self::assertArrayNotHasKey('machine.summary.accesslog', $enum);
    }

    public function testTrendWidgetListsTheBiggestDropByDefault(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        // Page 2 dropped from 100 to 10, page 3 grew from 10 to 100 - only
        // the drop should show up while the setting defaults to "declined".
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 10);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-40 days'), 'matomo.pageviews', 100);
        $this->get(DailyMetricsRepository::class)->upsert(3, 0, 'matomo', $today, 'matomo.pageviews', 100);
        $this->get(DailyMetricsRepository::class)->upsert(3, 0, 'matomo', $today->modify('-40 days'), 'matomo.pageviews', 10);

        $content = $this->renderTrendWidget([]);

        self::assertStringContainsString('About', $content);
        self::assertStringContainsString('pagemetrics-trend-negative', $content);
        self::assertStringNotContainsString('Restricted', $content);
        self::assertStringContainsString('pagemetrics-widget-trend-sentiment-negative', $content);
        // Declined, so the widget's own icon points down. Read off the aside
        // alone: every row carries a direction arrow of its own, and those
        // are the two icons this one must not be confused with.
        self::assertStringContainsString('tabler-trending-down', self::asideOf($content));
    }

    public function testTrendWidgetListsTheBiggestGainWhenConfigured(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 10);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-40 days'), 'matomo.pageviews', 100);
        $this->get(DailyMetricsRepository::class)->upsert(3, 0, 'matomo', $today, 'matomo.pageviews', 100);
        $this->get(DailyMetricsRepository::class)->upsert(3, 0, 'matomo', $today->modify('-40 days'), 'matomo.pageviews', 10);

        $content = $this->renderTrendWidget(['sentiment' => 'positive']);

        self::assertStringContainsString('Restricted', $content);
        self::assertStringContainsString('pagemetrics-trend-positive', $content);
        self::assertStringNotContainsString('About', $content);
        self::assertStringContainsString('pagemetrics-widget-trend-sentiment-positive', $content);
        self::assertStringNotContainsString('pagemetrics-widget-trend-sentiment-negative', $content);
        self::assertStringContainsString('tabler-trending-up', self::asideOf($content));
    }

    public function testTrendWidgetIconStaysNeutralForAMetricWithoutPolarity(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        // Avg. time on page is MetricPolarity::Neutral: the ranking falls
        // back to the literal direction, but no row carries a sentiment, so
        // the widget's own icon must not claim one either.
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.time_spent_seconds', 300);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.visits', 10);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-40 days'), 'matomo.time_spent_seconds', 100);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-40 days'), 'matomo.visits', 10);

        $content = $this->renderTrendWidget(['metric' => 'matomo.avg_time_on_page', 'sentiment' => 'positive']);

        self::assertStringContainsString('pagemetrics-widget-trend-sentiment-neutral', $content);
        self::assertStringContainsString('pagemetrics-trend-neutral', $content);
        self::assertStringNotContainsString('pagemetrics-widget-trend-sentiment-positive', $content);
        // No arrow where there is no better or worse to point at: an icon
        // claiming a direction over rows that carry none is the misreading
        // the whole polarity distinction exists to prevent.
        self::assertStringNotContainsString('tabler-trending-', self::asideOf($content));
    }

    public function testTrendWidgetRendersTheEmptyStateWhenNoSiteIsConfigured(): void
    {
        $result = $this->renderTrendWidgetResult([]);

        self::assertStringContainsString('callout-info', $result->content);
        self::assertNull($result->label);
    }

    public function testTrendWidgetLabelIncludesTheConfiguredMovementAndPeriod(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 10);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-40 days'), 'matomo.pageviews', 100);

        $result = $this->renderTrendWidgetResult(['sentiment' => 'positive', 'period' => 7]);

        self::assertStringContainsString('Page metrics trend', (string) $result->label);
        self::assertStringContainsString('Biggest improvements', (string) $result->label);
        self::assertStringContainsString('7 days', (string) $result->label);
    }

    public function testTrendWidgetRanksByTheConfiguredRateMetricInstead(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $previous = $today->modify('-40 days');
        // Page 2's bounce rate worsens from 20% to 80% - page views stay
        // flat, so this would only show up when ranking by bounce rate. A
        // rising bounce rate is a decline, not a gain, which is why the
        // setting here is "negative" rather than the direction the number
        // moved in.
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.entry_bounces', 8);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.entries', 10);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $previous, 'matomo.entry_bounces', 2);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $previous, 'matomo.entries', 10);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 50);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $previous, 'matomo.pageviews', 50);

        $result = $this->renderTrendWidgetResult(['metric' => 'matomo.bounce_rate', 'sentiment' => 'negative']);

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
        self::assertStringContainsString('pagemetrics-trend-positive', $content);
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

    public function testEachSettingsAwareWidgetSkipsSitesWithoutAConfiguredMetricProvider(): void
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

    private function seedDerivedPageMarkers(DateTimeImmutable $day): void
    {
        $repository = $this->get(AnnotationRepository::class);

        foreach ([1 => 'home', 2 => 'about', 5 => 'team'] as $pageUid => $id) {
            $repository->upsert('main', 'typo3', new Annotation($id, $day, 'Page updated', AnnotationCategory::Content, null, $pageUid));
        }
    }

    /**
     * @return list<array{date: string, dateUntil: ?string, category: string, text: string}>
     */
    private function annotationsOf(string $content): array
    {
        preg_match('/data-annotations="([^"]*)"/', $content, $matches);

        return json_decode(html_entity_decode($matches[1] ?? '[]'), true, 512, \JSON_THROW_ON_ERROR);
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
     * The icon column of a widget's aside, without the rows beside it. The
     * rows carry direction arrows of their own, so asserting on the whole
     * render would prove nothing about the one icon above them.
     */
    private static function asideOf(string $content): string
    {
        return explode('pagemetrics-widget-aside-content', $content)[0];
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
