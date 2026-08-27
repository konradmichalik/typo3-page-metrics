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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\UnresolvedReason;
use KonradMichalik\Typo3PageMetrics\Persistence\{AnnotationRepository, DailyMetricsRepository, UnresolvedUrlRepository};
use KonradMichalik\Typo3PageMetrics\Widgets\{AbstractSiteMetricsWidget, AnnotationsWidget, NotFoundPagesWidget, TopPagesWidget};
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * SiteMetricsWidgetsTest.
 *
 * Covers the three site-wide dashboard widgets still built on
 * AbstractSiteMetricsWidget (top pages, not-found pages, annotations)
 * together: same DI registration shape, same "one row per site" rendering,
 * so one test class per widget would mostly duplicate this exact setup.
 * The chart and trend widgets have their own settings-aware contract, see
 * SettingsAwareWidgetsTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SiteMetricsWidgetsTest extends FunctionalTestCase
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

    public function testTopPagesWidgetLinksTheHighestTrafficPageIntoThePageModuleAndShowsItsTrend(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 20);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-40 days'), 'matomo.pageviews', 10);

        $content = $this->renderWidget('dashboard.widget.pageMetricsTopPages', TopPagesWidget::class);

        self::assertStringContainsString('About', $content);
        self::assertStringContainsString('&amp;id=2', $content);
        self::assertStringContainsString('pagemetrics-trend-up', $content);
        self::assertStringContainsString('100%', $content);
    }

    public function testNotFoundPagesWidgetLinksTheFullUrlButOnlyDisplaysThePath(): void
    {
        $this->writeSiteConfiguration();
        $this->get(UnresolvedUrlRepository::class)->record('matomo', new DateTimeImmutable('today'), 'https://example.com/gone', 'matomo.pageviews', UnresolvedReason::NonPage, 3, 100);

        $content = $this->renderWidget('dashboard.widget.pageMetricsNotFoundPages', NotFoundPagesWidget::class);

        self::assertStringContainsString('href="https://example.com/gone"', $content);
        self::assertStringContainsString('>/gone<', $content);
        self::assertStringContainsString('Not a page', $content);
    }

    public function testAnnotationsWidgetListsSiteWideAnnotations(): void
    {
        $this->writeSiteConfiguration();
        $this->get(AnnotationRepository::class)->upsert('main', 'matomo', 'a1', new DateTimeImmutable('today'), 'Release 2.0');

        $content = $this->renderWidget('dashboard.widget.pageMetricsAnnotations', AnnotationsWidget::class);

        self::assertStringContainsString('Release 2.0', $content);
    }

    public function testEveryWidgetRendersTheEmptyStateWhenNoSiteIsConfigured(): void
    {
        self::assertStringContainsString('callout-info', $this->renderWidget('dashboard.widget.pageMetricsTopPages', TopPagesWidget::class));
        self::assertStringContainsString('callout-info', $this->renderWidget('dashboard.widget.pageMetricsNotFoundPages', NotFoundPagesWidget::class));
        self::assertStringContainsString('callout-info', $this->renderWidget('dashboard.widget.pageMetricsAnnotations', AnnotationsWidget::class));
    }

    /**
     * @param class-string<AbstractSiteMetricsWidget> $expectedClass
     */
    private function renderWidget(string $serviceId, string $expectedClass): string
    {
        $request = (new ServerRequest('https://example.com/typo3/module/dashboard'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        $widget = $this->get($serviceId);
        self::assertInstanceOf($expectedClass, $widget);
        self::assertInstanceOf(AbstractSiteMetricsWidget::class, $widget);
        $widget->setRequest($request);

        return $widget->renderWidgetContent();
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
