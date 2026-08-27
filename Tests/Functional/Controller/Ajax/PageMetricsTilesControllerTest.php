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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Controller\Ajax;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Controller\Ajax\PageMetricsTilesController;
use KonradMichalik\Typo3PageMetrics\Persistence\{AnnotationRepository, DailyMetricsRepository};
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * PageMetricsTilesControllerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class PageMetricsTilesControllerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    public function testRendersEmptyResponseWhenNoPageIsSelected(): void
    {
        $response = $this->dispatch(['id' => '0']);

        self::assertSame('', (string) $response->getBody());
    }

    public function testRendersTilesForTheSelectedPage(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 42);

        $response = $this->dispatch(['id' => '2']);

        self::assertStringContainsString('42', (string) $response->getBody());
        self::assertStringContainsString('Page views', (string) $response->getBody());
    }

    public function testRendersTheExternalMatomoLinkAsADataAttribute(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');

        $response = $this->dispatch(['id' => '2']);
        $body = (string) $response->getBody();

        self::assertStringContainsString('data-external-link="', $body);
        self::assertStringContainsString('matomo.example.com', $body);
    }

    public function testRendersTrendDeltaAndRedirectShareOnTheTile(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 30, '');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 6, 'via_redirect');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-10 days'), 'matomo.pageviews', 20);

        $response = $this->dispatch(['id' => '2', 'period' => '7']);

        self::assertStringContainsString('pagemetrics-tile-delta-up', (string) $response->getBody());
        self::assertStringContainsString('50%', (string) $response->getBody());
        self::assertStringContainsString('data-redirect-share="20"', (string) $response->getBody());
    }

    public function testRendersZeroPercentDeltaAsANumberRatherThanNew(): void
    {
        // Regression test: Fluid's `!=` operator does a loose PHP comparison,
        // under which `0 != null` is false (since `0 == null` is true) -
        // the template must use the strict `!==` operator, or an exactly
        // flat, non-empty previous period incorrectly renders "new".
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 20);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-10 days'), 'matomo.pageviews', 20);

        $response = $this->dispatch(['id' => '2', 'period' => '7']);
        $body = (string) $response->getBody();

        self::assertStringContainsString('pagemetrics-tile-delta-flat', $body);
        self::assertStringContainsString('0%', $body);
    }

    public function testRendersTheBounceRateAndAvgTimeOnPageTilesWithTheirIconsAndUnitAttribute(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.time_spent_seconds', 90);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.visits', 1);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.entry_bounces', 3);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.entries', 4);

        $response = $this->dispatch(['id' => '2']);
        $body = (string) $response->getBody();

        self::assertStringContainsString('Avg. time on page', $body);
        self::assertStringContainsString('1:30', $body);
        self::assertStringContainsString('data-unit="seconds"', $body);
        self::assertStringContainsString('Bounce rate', $body);
        self::assertStringContainsString('75%', $body);
        self::assertStringContainsString('data-unit="percent"', $body);
        self::assertStringContainsString('tabler-clock', $body);
        self::assertStringContainsString('tabler-door-exit', $body);
    }

    public function testRendersTheTrendTooltipForAPercentTileWithoutCrashing(): void
    {
        // Regression test: the trend tooltip's title attribute is built via
        // f:translate's sprintf-style `arguments`, and a percent-unit tile's
        // formatted value contains a literal "%" (e.g. "20%") - if the
        // translation ever falls back to its `default` text and that default
        // was written with pre-resolved Fluid placeholders instead of %1$s
        // style ones, TYPO3 core's vsprintf() call chokes on that trailing
        // "%" with a ValueError. Both period's bounce rates must be real and
        // different so deltaPercent is neither null nor zero.
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.entry_bounces', 3);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.entries', 4);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-10 days'), 'matomo.entry_bounces', 2);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-10 days'), 'matomo.entries', 10);

        $response = $this->dispatch(['id' => '2', 'period' => '7']);
        $body = (string) $response->getBody();

        self::assertStringContainsString('Previous period', $body);
        self::assertStringContainsString('20%', $body);
    }

    public function testRendersAnnotationsAsADataAttributeOnTheTilesContainer(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $this->get(AnnotationRepository::class)->upsert('main', 'matomo', '1', new DateTimeImmutable('today'), 'Release 2.0');

        $response = $this->dispatch(['id' => '2']);
        $body = (string) $response->getBody();

        self::assertStringContainsString('data-annotations="', $body);
        self::assertStringContainsString('Release 2.0', $body);
    }

    public function testIncludeSubpagesSumsMetricsAcrossTheSubtreeAndMarksTheContainer(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.pageviews', 10);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 32);

        $response = $this->dispatch(['id' => '1', 'includeSubpages' => '1']);
        $body = (string) $response->getBody();

        self::assertStringContainsString('data-include-subpages="1"', $body);
        self::assertStringContainsString('42', $body);
    }

    public function testIncludeSubpagesHidesTheExternalLinkDataAttribute(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');

        $response = $this->dispatch(['id' => '1', 'includeSubpages' => '1']);
        $body = (string) $response->getBody();

        self::assertStringNotContainsString('matomo.example.com', $body);
    }

    public function testRendersTheSubpagesOptionForAPageWithChildren(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');

        $response = $this->dispatch(['id' => '1']);
        $body = (string) $response->getBody();

        self::assertStringContainsString('data-options-trigger', $body);
        self::assertStringContainsString('data-include-subpages-toggle', $body);
    }

    public function testShowsACheckmarkInTheSubpagesMenuItemWhenActive(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');

        $response = $this->dispatch(['id' => '1', 'includeSubpages' => '1']);
        $body = (string) $response->getBody();

        self::assertStringContainsString('pagemetrics-options-menu-item-active', $body);
        self::assertStringContainsString('<svg viewBox="0 0 24 24"', $body);
    }

    public function testOmitsTheSubpagesOptionForALeafPage(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');

        $response = $this->dispatch(['id' => '2']);
        $body = (string) $response->getBody();

        self::assertStringNotContainsString('data-options-trigger', $body);
        self::assertStringNotContainsString('data-include-subpages-toggle', $body);
    }

    public function testRendersEmptyResponseWhenBackendUserLacksPageAccess(): void
    {
        // Page 3 in the fixture has perms_everybody = 0 and perms_userid = 1,
        // so a non-admin backend user (uid 2) has no PAGE_SHOW permission on
        // it even though the site itself resolves the page just fine.
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $backendUser = $this->setUpBackendUser(2);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $response = $this->dispatch(['id' => '3']);

        self::assertSame('', (string) $response->getBody());
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function dispatch(array $queryParams): ResponseInterface
    {
        $route = new Route('/page-metrics/tiles', ['packageName' => 'konradmichalik/typo3-page-metrics']);
        $request = (new ServerRequest('https://example.com/typo3/ajax/page-metrics/tiles'))
            ->withQueryParams($queryParams)
            ->withAttribute('route', $route)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        return $this->get(PageMetricsTilesController::class)->handleRequest($request);
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
