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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Controller;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Controller\PageMetricsModuleController;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\AnnotationCategory;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\Annotation;
use KonradMichalik\Typo3PageMetrics\Persistence\{AnnotationRepository, DailyMetricsRepository};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\{NullSite, SiteInterface};
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * PageMetricsModuleControllerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class PageMetricsModuleControllerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Ajax/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__.'/Ajax/Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    public function testAsksForAPageWhenTheTreeHasNotBeenUsedYet(): void
    {
        // The state the module opens in every time, so it has to read as an
        // instruction rather than as something having gone wrong.
        $body = (string) $this->dispatch([])->getBody();

        self::assertStringContainsString('Select a page in the page tree', $body);
    }

    public function testRendersTheFrameForAPageTheUserMaySee(): void
    {
        $body = (string) $this->dispatch(['id' => '2'])->getBody();

        self::assertStringContainsString('pagemetrics-module', $body);
        self::assertStringContainsString('data-page-id="2"', $body);
    }

    public function testDrawsTheDocheaderAroundTheView(): void
    {
        // The period switch and the bookmark are registered on the docheader,
        // which only exists if the template declares the Module layout. Both
        // suites passed without it and the module rendered its bare body, so
        // this asserts the chrome and not just the content.
        $body = (string) $this->dispatch(['id' => '2'])->getBody();

        self::assertStringContainsString('module-docheader', $body);
        self::assertStringContainsString('7 days', $body);
        self::assertStringContainsString('90 days', $body);
    }

    public function testOffersEveryPeriodTheTilesOfferAndMarksTheStoredOneActive(): void
    {
        $body = (string) $this->dispatch(['id' => '2'], ['period' => 90])->getBody();

        self::assertStringContainsString('data-period="90"', $body);
    }

    public function testFallsBackToTheDefaultPeriodForAValueTheModuleDoesNotOffer(): void
    {
        // moduleData is persisted per user and survives an upgrade, so a
        // value that was once valid, or one typed into the URL, must not
        // reach the queries behind a view.
        $body = (string) $this->dispatch(['id' => '2'], ['period' => 4000])->getBody();

        self::assertStringContainsString('data-period="30"', $body);
    }

    public function testListsEveryMetricAsAReadableRowRatherThanAHover(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 30);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-40 days'), 'matomo.pageviews', 20);

        $body = (string) $this->dispatch(['id' => '2'])->getBody();

        // The label, the figure and the provider's own description, all of it
        // read rather than hovered. The description is what the popover hides
        // behind a mouse and the reason this view exists.
        self::assertStringContainsString('Page views', $body);
        self::assertStringContainsString('The number of times this page was viewed', $body);
        self::assertStringContainsString('pagemetrics-module-table', $body);
    }

    public function testDrawsTheChartWithTheSamePayloadTheWidgetsUse(): void
    {
        $this->writeSiteConfiguration();
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 30);

        $body = (string) $this->dispatch(['id' => '2'])->getBody();

        // initializeStaticCharts() in sparkline.js renders any element with
        // this class, so the module needs no chart code of its own.
        self::assertStringContainsString('pagemetrics-widget-chart', $body);
        self::assertStringContainsString('data-sparkline', $body);
    }

    public function testListsThePeriodsMarkersWithTheirKindAndText(): void
    {
        $this->writeSiteConfiguration();
        $day = (new DateTimeImmutable('today'))->modify('-2 days');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $day, 'matomo.pageviews', 30);
        $this->get(AnnotationRepository::class)->upsert('main', 'typo3', new Annotation('1', $day, 'Homepage rewritten', AnnotationCategory::Content, null, 2));

        $body = (string) $this->dispatch(['id' => '2'])->getBody();

        self::assertStringContainsString('Homepage rewritten', $body);
        self::assertStringContainsString('Content change', $body);
    }

    public function testSaysSoWhenTheSiteHasNoProviderConfigured(): void
    {
        // No site configuration at all, so no provider supports it. That is a
        // site-configuration answer and not an empty period, and saying
        // "nothing happened" would be the wrong one.
        $body = (string) $this->dispatch(['id' => '2'])->getBody();

        self::assertStringContainsString('No metric provider is configured', $body);
    }

    public function testTheCompareViewRendersItsComparisonRatherThanThePageView(): void
    {
        // The controller only builds the comparison for the view that reads
        // it, so this is what proves the wiring and not just the template.
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 30);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-40 days'), 'matomo.pageviews', 20);

        // The baseline picker is what only the compare view has, so it is
        // what proves the dispatch went to the right template and that the
        // controller built the comparison the template needs.
        $body = (string) $this->dispatch(['id' => '2'], ['view' => 'compare'])->getBody();

        self::assertStringContainsString('pagemetrics-compare-picker', $body);
    }

    public function testThePageViewDoesNotCarryTheComparePicker(): void
    {
        // One dispatch per test: the docheader is container-scoped, so two
        // renders in one test share state that two requests never would.
        $this->writeSiteConfiguration();

        $body = (string) $this->dispatch(['id' => '2'], ['view' => 'page'])->getBody();

        self::assertStringNotContainsString('pagemetrics-compare-picker', $body);
    }

    public function testOffersTheViewSwitchOnceThereIsMoreThanOneView(): void
    {
        $body = (string) $this->dispatch(['id' => '2'])->getBody();

        self::assertStringContainsString('Compare', $body);
        self::assertStringContainsString('Pages', $body);
    }

    public function testThePagesViewRendersTheSitesTable(): void
    {
        // The builder needs the site off the request, which the test has to
        // supply the way the backend site resolver would, so this is what
        // proves that wiring and not just the template.
        $this->writeSiteConfiguration();
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 30);

        $body = (string) $this->dispatch(['id' => '2'], ['view' => 'pages'])->getBody();

        self::assertStringContainsString('aria-sort', $body);
        self::assertStringNotContainsString('This view is being built', $body);
    }

    public function testRefusesAPageTheUserMayNotSee(): void
    {
        // Page 3 has perms_everybody = 0, so the editor has no PAGE_SHOW on
        // it. The uid arrives as a request parameter, so this check is what
        // stands between a hand-built URL and another page's numbers.
        $this->actAs(2);

        $body = (string) $this->dispatch(['id' => '3'])->getBody();

        self::assertStringContainsString('do not have permission', $body);
        self::assertStringNotContainsString('pagemetrics-module', $body);
    }

    public function testRefusesAUserTheTSconfigSwitchTurnedItOffFor(): void
    {
        // The module path is reachable by typing it, so the switch that hides
        // the tiles is enforced on the route as well and not only wherever a
        // menu entry might have been suppressed.
        $this->actAs(3);

        $body = (string) $this->dispatch(['id' => '2'])->getBody();

        self::assertStringContainsString('switched off', $body);
        self::assertStringNotContainsString('pagemetrics-module', $body);
    }

    private function actAs(int $backendUserUid): void
    {
        $backendUser = $this->setUpBackendUser($backendUserUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    /**
     * @param array<string, string> $queryParams
     * @param array<string, mixed>  $moduleData
     */
    private function dispatch(array $queryParams, array $moduleData = []): ResponseInterface
    {
        return $this->get(PageMetricsModuleController::class)->handleRequest($this->buildRequest($queryParams, $moduleData));
    }

    /**
     * BackendModuleValidator is what merges a query parameter into the stored
     * moduleData and attaches it, so a test that calls the controller
     * directly hands it the attribute the middleware would have set.
     *
     * @param array<string, string> $queryParams
     * @param array<string, mixed>  $moduleData
     */
    private function buildRequest(array $queryParams, array $moduleData): ServerRequestInterface
    {
        $route = new Route('/module/web/pagemetrics', ['packageName' => 'konradmichalik/typo3-page-metrics']);
        $request = (new ServerRequest('https://example.com/typo3/module/web/pagemetrics'))
            ->withQueryParams($queryParams)
            ->withAttribute('route', $route)
            ->withAttribute('moduleData', new ModuleData('web_pagemetrics', $moduleData))
            ->withAttribute('site', $this->siteAttribute())
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    /**
     * What the backend's own site resolver puts on the request from the same
     * page uid. A NullSite where no site configuration was written, which is
     * exactly what a view asking about "the site" has to cope with.
     */
    private function siteAttribute(): SiteInterface
    {
        try {
            return $this->get(SiteFinder::class)->getSiteByPageId(2);
        } catch (SiteNotFoundException) {
            return new NullSite();
        }
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
            'pageMetrics' => [
                'providers' => ['matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 1, 'tokenAuth' => 'x']],
            ],
        ]);
    }
}
