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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Controller;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Controller\PageMetricsController;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * PageMetricsControllerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class PageMetricsControllerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    public function testRendersNeutralStateWhenNoPageIsSelected(): void
    {
        $controller = $this->get(PageMetricsController::class);
        $request = $this->buildModuleRequest(['id' => '0']);

        $response = $controller->handleRequest($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Select a page', (string) $response->getBody());
    }

    public function testRendersTileTotalForConfiguredMetric(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $configuration = $this->buildSiteConfiguration(1, 'https://example.com/');
        $configuration['pageMetrics'] = ['sources' => ['matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 1, 'tokenAuth' => 'x']]];
        $this->writeSiteConfiguration('main', $configuration);
        $dailyMetricsRepository = $this->get(\KonradMichalik\Typo3PageMetrics\Persistence\DailyMetricsRepository::class);
        $today = new DateTimeImmutable('today');
        $dailyMetricsRepository->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 42);

        $controller = $this->get(PageMetricsController::class);
        $request = $this->buildModuleRequest(['id' => '2']);

        $response = $controller->handleRequest($request);

        self::assertStringContainsString('42', (string) $response->getBody());
        self::assertStringContainsString('Page views', (string) $response->getBody());
    }

    public function testPeriodSwitchChangesTheAggregationWindow(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $configuration = $this->buildSiteConfiguration(1, 'https://example.com/');
        $configuration['pageMetrics'] = ['sources' => ['matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 1, 'tokenAuth' => 'x']]];
        $this->writeSiteConfiguration('main', $configuration);
        $dailyMetricsRepository = $this->get(\KonradMichalik\Typo3PageMetrics\Persistence\DailyMetricsRepository::class);
        $today = new DateTimeImmutable('today');
        $dailyMetricsRepository->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 5);
        $dailyMetricsRepository->upsert(2, 0, 'matomo', $today->modify('-40 days'), 'matomo.pageviews', 100);

        $controller = $this->get(PageMetricsController::class);
        $request = $this->buildModuleRequest(['id' => '2', 'period' => '7']);

        $response = $controller->handleRequest($request);

        // Scoped to the tile-value markup rather than a bare substring check: the
        // full rendered module page also embeds dozens of random CSRF tokens in
        // its JS settings blob, and a bare "105" can appear there by pure digit
        // coincidence, which would make this assertion flaky.
        self::assertStringContainsString('pagemetrics-tile-value">5<', (string) $response->getBody());
        self::assertStringNotContainsString('pagemetrics-tile-value">105<', (string) $response->getBody());
    }

    public function testRendersNeutralStateWhenBackendUserLacksPageAccess(): void
    {
        // Page 3 in the fixture has perms_everybody = 0 and perms_userid = 1,
        // so a non-admin backend user (uid 2) has no PAGE_SHOW permission on
        // it even though the site itself resolves the page just fine.
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->writeSiteConfiguration('main', $this->buildSiteConfiguration(1, 'https://example.com/'));
        $backendUser = $this->setUpBackendUser(2);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $controller = $this->get(PageMetricsController::class);
        $request = $this->buildModuleRequest(['id' => '3']);

        $response = $controller->handleRequest($request);

        self::assertStringContainsString('Select a page', (string) $response->getBody());
    }

    public function testShowsUnresolvedCountWhenUnresolvedUrlsExistForTheSite(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->writeSiteConfiguration('main', $this->buildSiteConfiguration(1, 'https://example.com/'));
        $this->get(\KonradMichalik\Typo3PageMetrics\Persistence\UnresolvedUrlRepository::class)->record(
            'matomo',
            new DateTimeImmutable('today'),
            'https://example.com/gone',
            'matomo.pageviews',
            \KonradMichalik\Typo3PageMetrics\Domain\Enum\UnresolvedReason::Unknown,
            3,
            100,
        );

        $controller = $this->get(PageMetricsController::class);
        $request = $this->buildModuleRequest(['id' => '2']);

        $response = $controller->handleRequest($request);

        // A bare "1" substring check would be a false positive: the rendered
        // module page already contains incidental "1" digits (page IDs, CSRF
        // tokens) even without the unresolved-URL indicator implemented, so the
        // assertion is scoped to the actual hint text instead.
        self::assertStringContainsString('1 URLs from the last 30 days could not be matched to a page on this site.', (string) $response->getBody());
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function buildModuleRequest(array $queryParams): ServerRequest
    {
        // BackendViewFactory::create() reads the current route from the request
        // attribute to resolve template paths, and ModuleTemplate's PageRenderer
        // setup needs applicationType/normalizedParams; the real backend router
        // adds all three during dispatch, so a functional test invoking the
        // controller directly has to fake them.
        $route = new Route('/module/web/pagemetrics', ['packageName' => 'konradmichalik/typo3-page-metrics']);
        $request = (new ServerRequest('https://example.com/typo3/module/web/pagemetrics'))
            ->withQueryParams($queryParams)
            ->withAttribute('route', $route)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSiteConfiguration(int $rootPageId, string $base): array
    {
        return [
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
        ];
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function writeSiteConfiguration(string $identifier, array $configuration): void
    {
        // TYPO3 v14's testing-framework 9.x dropped SiteBasedTestTrait; the core
        // SiteWriter service is the direct replacement for persisting a site
        // configuration in functional tests.
        $this->get(SiteWriter::class)->write($identifier, $configuration);
    }
}
