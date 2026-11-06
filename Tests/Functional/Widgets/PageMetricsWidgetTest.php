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
use JsonException;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\Annotation;
use KonradMichalik\Typo3PageMetrics\Persistence\{AnnotationRepository, DailyMetricsRepository};
use KonradMichalik\Typo3PageMetrics\Widgets\PageMetricsWidget;
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
 * PageMetricsWidgetTest.
 *
 * PageMetricsWidget implements WidgetRendererInterface for its Period
 * setting, a TYPO3 14 addition (see Configuration/Services.php) - on
 * TYPO3 13.4 the widget is not registered at all, so there is nothing to
 * fetch from the container.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class PageMetricsWidgetTest extends FunctionalTestCase
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
            self::markTestSkipped('PageMetricsWidget requires TYPO3 14 (WidgetRendererInterface).');
        }

        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    public function testIsRegisteredAsADashboardWidgetOnlyBecauseExtDashboardIsLoaded(): void
    {
        self::assertInstanceOf(PageMetricsWidget::class, $this->get('dashboard.widget.pageMetrics'));
    }

    public function testRendersEachSitesHomepageTiles(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 42);

        $content = $this->renderWidget([]);

        self::assertStringContainsString('main', $content);
        self::assertStringContainsString('42', $content);
        self::assertStringContainsString('Page views', $content);
        self::assertStringContainsString('pagemetrics-tile', $content);
    }

    public function testRendersTheEmptyStateWhenNoSiteIsConfigured(): void
    {
        $content = $this->renderWidget([]);

        self::assertStringContainsString('callout-info', $content);
    }

    public function testTilesAreInteractiveWithSparklineDataAndAnnotations(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.pageviews', 42);
        $this->get(AnnotationRepository::class)->upsert('main', 'matomo', new Annotation('a1', $today, 'Release 2.0'));

        $content = $this->renderWidget([]);

        self::assertStringContainsString('data-sparkline="', $content);
        self::assertStringContainsString('tabindex="0"', $content);
        self::assertStringContainsString('role="button"', $content);
        self::assertStringContainsString('aria-haspopup="true"', $content);
        self::assertStringContainsString('&quot;Release 2.0&quot;', $content);
    }

    public function testLoadsTheSparklineJavaScriptModule(): void
    {
        $widget = $this->get('dashboard.widget.pageMetrics');
        self::assertInstanceOf(PageMetricsWidget::class, $widget);

        $modules = $widget->getJavaScriptModuleInstructions();

        self::assertCount(1, $modules);
        self::assertInstanceOf(JavaScriptModuleInstruction::class, $modules[0]);
    }

    public function testDeclaresItsBackendAssetsAndOffersAPeriodSetting(): void
    {
        $widget = $this->get('dashboard.widget.pageMetrics');
        self::assertInstanceOf(PageMetricsWidget::class, $widget);

        self::assertSame(['EXT:typo3_page_metrics/Resources/Public/Css/page-metrics.css'], $widget->getCssFiles());

        $definitions = $widget->getSettingsDefinitions();
        self::assertCount(1, $definitions);
        self::assertSame('period', $definitions[0]->key);
        self::assertSame('int', $definitions[0]->type);
        self::assertSame(7, $definitions[0]->default);
        self::assertArrayHasKey(7, $definitions[0]->enum);
        self::assertArrayHasKey(30, $definitions[0]->enum);
        self::assertArrayHasKey(90, $definitions[0]->enum);
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

    public function testDefaultsToASevenDayPeriod(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 42);

        self::assertCount(7, $this->firstTileSparkline($this->renderWidget([])));
    }

    public function testRespectsTheConfiguredPeriod(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 42);

        self::assertCount(90, $this->firstTileSparkline($this->renderWidget(['period' => 90])));
    }

    public function testFallsBackToTheDefaultPeriodWhenAnInvalidValueIsConfigured(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 42);

        self::assertCount(7, $this->firstTileSparkline($this->renderWidget(['period' => 14])));
    }

    public function testLabelIncludesTheConfiguredPeriod(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 42);

        $result = $this->renderWidgetResult(['period' => 30]);

        self::assertStringContainsString('Page metrics', (string) $result->label);
        self::assertStringContainsString('30', (string) $result->label);
    }

    /**
     * @return list<mixed>
     *
     * @throws JsonException
     */
    private function firstTileSparkline(string $content): array
    {
        preg_match('/data-sparkline="([^"]*)"/', $content, $matches);
        $raw = $matches[1] ?? self::fail('data-sparkline attribute not found in widget content');

        return json_decode(html_entity_decode($raw), true, 512, \JSON_THROW_ON_ERROR);
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
        $widget = $this->get('dashboard.widget.pageMetrics');
        self::assertInstanceOf(PageMetricsWidget::class, $widget);

        return $widget->renderWidget($this->buildContext($widget->getSettingsDefinitions(), $settings));
    }

    /**
     * @param array<int, SettingDefinition> $definitions
     * @param array<string, mixed>          $settings
     */
    private function buildContext(array $definitions, array $settings): WidgetContext
    {
        $configuration = $this->get('pageMetricsWidgetConfiguration');
        self::assertInstanceOf(WidgetConfigurationInterface::class, $configuration);

        $request = (new ServerRequest('https://example.com/typo3/module/dashboard'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        $resolvedSettings = $this->get(WidgetSettingsFactory::class)->createSettings('pageMetrics', $settings, $definitions);
        self::assertInstanceOf(SettingsInterface::class, $resolvedSettings);

        return new WidgetContext('pageMetrics', [], $configuration, $resolvedSettings, $request);
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
}
