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
use KonradMichalik\Typo3PageMetrics\Widgets\PageMetricsWidget;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * PageMetricsWidgetTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class PageMetricsWidgetTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    protected function setUp(): void
    {
        parent::setUp();
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

        $content = $this->renderWidget();

        self::assertStringContainsString('main', $content);
        self::assertStringContainsString('42', $content);
        self::assertStringContainsString('Page views', $content);
        self::assertStringContainsString('pagemetrics-tile', $content);
    }

    public function testRendersTheEmptyStateWhenNoSiteIsConfigured(): void
    {
        $content = $this->renderWidget();

        self::assertStringContainsString('callout-info', $content);
    }

    public function testTilesAreInteractiveWithSparklineDataAndAnnotations(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.pageviews', 42);
        $this->get(AnnotationRepository::class)->upsert('main', 'matomo', 'a1', $today, 'Release 2.0');

        $content = $this->renderWidget();

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

    private function renderWidget(): string
    {
        $request = (new ServerRequest('https://example.com/typo3/module/dashboard'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        $widget = $this->get('dashboard.widget.pageMetrics');
        self::assertInstanceOf(PageMetricsWidget::class, $widget);
        $widget->setRequest($request);

        return $widget->renderWidgetContent();
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
            'pageMetrics' => ['sources' => ['matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 1, 'tokenAuth' => 'x']]],
        ]);
    }
}
