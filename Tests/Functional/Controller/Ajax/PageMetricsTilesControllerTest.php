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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Controller\Ajax;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Controller\Ajax\PageMetricsTilesController;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{Annotation, DateRange};
use KonradMichalik\Typo3PageMetrics\Persistence\{AnnotationRepository, DailyMetricsRepository, ImportStateRepository};
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function explode;
use function sprintf;
use function str_contains;

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

    public function testHandsTheSparklineLabelsToTheClientInsteadOfHardCodingThemInJavaScript(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 7);

        $body = (string) $this->dispatch(['id' => '2', 'period' => '30'])->getBody();

        self::assertStringContainsString('data-labels=', $body);
        // The link label no longer names a provider the tile may not come
        // from: this same markup is rendered for GA4 and access-log tiles.
        self::assertStringContainsString('Open in analytics tool', $body);
        self::assertStringNotContainsString('Open in Matomo', $body);
    }

    public function testRendersTheDayTheNumbersAreFrom(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today->modify('-1 day'), new DateRange($today->modify('-30 days'), $today->modify('-1 day')), true);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-1 day'), 'matomo.pageviews', 7);

        $body = (string) $this->dispatch(['id' => '2', 'period' => '30'])->getBody();

        self::assertStringContainsString('pagemetrics-freshness', $body);
        self::assertStringContainsString($today->modify('-1 day')->format('Y-m-d'), $body);
        self::assertStringNotContainsString('pagemetrics-freshness-stale', $body);
        // The extension's own tooltip, not the browser's: a title attribute
        // waits about a second and cannot be styled to match the backend.
        self::assertStringContainsString('data-hint-title="As of', $body);
        self::assertStringNotContainsString('<span class="pagemetrics-freshness" title=', $body);
    }

    public function testMarksTheDateStaleOnceTheImportIsMoreThanTwoDaysBehind(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today->modify('-4 days'), new DateRange($today->modify('-30 days'), $today->modify('-3 days')), true);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-3 days'), 'matomo.pageviews', 7);

        $body = (string) $this->dispatch(['id' => '2', 'period' => '30'])->getBody();

        self::assertStringContainsString('pagemetrics-freshness-stale', $body);
    }

    public function testRendersNoDateBeforeAnythingHasBeenImported(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 7);

        $body = (string) $this->dispatch(['id' => '2', 'period' => '30'])->getBody();

        self::assertStringNotContainsString('pagemetrics-freshness', $body);
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

        $tile = self::tileMarkup((string) $this->dispatch(['id' => '2'])->getBody(), 'matomo.pageviews');

        self::assertStringContainsString('42', $tile);
        self::assertStringContainsString('Page views', $tile);
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

        $tile = self::tileMarkup((string) $this->dispatch(['id' => '2', 'period' => '7'])->getBody(), 'matomo.pageviews');

        self::assertStringContainsString('pagemetrics-trend-positive', $tile);
        self::assertStringContainsString('pagemetrics-trend-pill', $tile);
        self::assertStringContainsString('50%', $tile);
        self::assertStringContainsString('data-redirect-share="20"', $tile);
        // The badge's own claim first, in the same tooltip every other
        // explained control in this extension uses: the heading names the two
        // figures the percentage is drawn from, the second line the periods
        // they belong to.
        self::assertStringContainsString('data-hint-title="30 against 20 in the previous period"', $tile);
        self::assertStringContainsString('data-hint-text="This period: ', $tile);
        self::assertStringContainsString('Previous period: ', $tile);
    }

    public function testTheTrendBadgeSpellsOutTheMovementForAssistiveTechnology(): void
    {
        // The arrow and the colour both say this visually, and neither
        // survives a screen reader or a forced-colours mode.
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 30);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-10 days'), 'matomo.pageviews', 20);

        $tile = self::tileMarkup((string) $this->dispatch(['id' => '2', 'period' => '7'])->getBody(), 'matomo.pageviews');

        self::assertStringContainsString('pagemetrics-visually-hidden', $tile);
        self::assertStringContainsString('Improved by', $tile);
    }

    public function testRendersZeroPercentDeltaAsANumberRatherThanNew(): void
    {
        // A page whose numbers did not move says so, the way the widget
        // tables already do. It used to say nothing at all, and a tile with
        // no badge is ambiguous between "unchanged" and "nothing to compare".
        //
        // Asserted against this tile's own markup: the response carries three
        // more tiles with no data, and each of those renders a neutral badge
        // and a percent sign of its own.
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 20);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-10 days'), 'matomo.pageviews', 20);

        $tile = self::tileMarkup((string) $this->dispatch(['id' => '2', 'period' => '7'])->getBody(), 'matomo.pageviews');

        self::assertStringContainsString('pagemetrics-trend-neutral', $tile);
        self::assertStringContainsString('0%', $tile);
        // The arrow and the colour do not survive a screen reader.
        self::assertStringContainsString('Unchanged by', $tile);
    }

    public function testATileWithNothingInAnImportedPreviousPeriodReadsNewRatherThanABarePercentSign(): void
    {
        // Fluid cannot compare against null (see MetricTile::$isNew), so the
        // template asks the tile rather than the delta: `!== null` in an
        // f:if condition is never false, and the badge used to render the
        // percent sign of a percentage that does not exist.
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today, new DateRange($today->modify('-13 days'), $today), true);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 20);

        $tile = self::tileMarkup((string) $this->dispatch(['id' => '2', 'period' => '7'])->getBody(), 'matomo.pageviews');

        self::assertStringContainsString('data-hint-title="new"', $tile);
        self::assertStringContainsString('No data in the previous period', $tile);
        self::assertStringContainsString('No previous period to compare against', $tile);
    }

    public function testRendersNoBadgeAtAllWhenThePreviousPeriodWasNeverImported(): void
    {
        // A third state: "no comparison possible" is not "new since last
        // period", and the tile makes no claim it has no data for.
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today, new DateRange($today->modify('-6 days'), $today), true);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 20);

        $tile = self::tileMarkup((string) $this->dispatch(['id' => '2', 'period' => '7'])->getBody(), 'matomo.pageviews');

        self::assertStringContainsString('20', $tile);
        self::assertStringNotContainsString('pagemetrics-trend', $tile);
    }

    public function testRendersTheBounceRateAndAvgTimeOnPageTilesWithTheirIconsAndUnitAttribute(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.time_spent_seconds', 90);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.visits', 1);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.entry_bounces', 3);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.entries', 4);

        $body = (string) $this->dispatch(['id' => '2'])->getBody();
        $timeTile = self::tileMarkup($body, 'matomo.avg_time_on_page');
        $bounceTile = self::tileMarkup($body, 'matomo.bounce_rate');

        self::assertStringContainsString('Avg. time on page', $timeTile);
        self::assertStringContainsString('1:30', $timeTile);
        self::assertStringContainsString('data-unit="seconds"', $timeTile);
        self::assertStringContainsString('tabler-clock', $timeTile);
        self::assertStringContainsString('Bounce rate', $bounceTile);
        self::assertStringContainsString('75%', $bounceTile);
        self::assertStringContainsString('data-unit="percent"', $bounceTile);
        self::assertStringContainsString('tabler-door-exit', $bounceTile);
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

        $tile = self::tileMarkup((string) $this->dispatch(['id' => '2', 'period' => '7'])->getBody(), 'matomo.bounce_rate');

        self::assertStringContainsString('Previous period: ', $tile);
        self::assertStringContainsString('20%', $tile);
    }

    public function testRendersAnnotationsAsADataAttributeOnTheTilesContainer(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $this->get(AnnotationRepository::class)->upsert('main', 'matomo', new Annotation('1', new DateTimeImmutable('today'), 'Release 2.0'));

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

        $body = (string) $this->dispatch(['id' => '1', 'includeSubpages' => '1'])->getBody();

        self::assertStringContainsString('data-include-subpages="1"', $body);
        self::assertStringContainsString('42', self::tileMarkup($body, 'matomo.pageviews'));
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

    public function testOffersAddMarkerOnALeafPageOnceTheSiteNamesAStoragePage(): void
    {
        // Page 2 has no children, so before the marker entry existed this
        // page had no options menu at all: the menu itself has to appear
        // for the entry, not only the entry inside an existing menu.
        $this->writeSiteConfiguration('main', 1, 'https://example.com/', 4);

        $body = (string) $this->dispatch(['id' => '2'])->getBody();

        self::assertStringContainsString('data-options-trigger', $body);
        self::assertStringContainsString('Add marker', $body);
        // The generated path, not the route name.
        self::assertStringContainsString('/typo3/record/edit', $body);
    }

    public function testOmitsAddMarkerWhenTheSiteNamesNoStoragePage(): void
    {
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');

        $body = (string) $this->dispatch(['id' => '1'])->getBody();

        self::assertStringContainsString('data-options-trigger', $body);
        self::assertStringNotContainsString('Add marker', $body);
    }

    public function testOffersTheWayIntoTheModuleBesideTheDateMark(): void
    {
        // The tile row is where an editor already looks, and until now
        // nothing on it said that a fuller reading of the same page exists.
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');

        $body = (string) $this->dispatch(['id' => '2'])->getBody();

        self::assertStringContainsString('pagemetrics-module-link', $body);
        self::assertStringContainsString('module/web/pagemetrics', $body);
        self::assertStringContainsString('Open full view', $body);
    }

    public function testTheWayIntoTheModuleCarriesThePageAndNotThePeriod(): void
    {
        // The page module's period is URL state; the module's is a stored
        // per-user preference, and a query parameter named like a moduleData
        // key overwrites it on the way in. So the link names the page and
        // leaves the reader's own period alone.
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');

        $body = (string) $this->dispatch(['id' => '2', 'period' => '90'])->getBody();

        self::assertMatchesRegularExpression('#pagemetrics-module-link" href="[^"]*id=2[^"]*"#', $body);
        self::assertDoesNotMatchRegularExpression('#pagemetrics-module-link" href="[^"]*period=#', $body);
    }

    public function testOffersNoWayIntoTheModuleToAUserWhoCannotOpenIt(): void
    {
        // The module is declared 'access' => 'user' and this editor is in no
        // group that lists it, so the route would refuse them. A link to
        // that is worse than no link. Their own tiles still render, which is
        // why this user needs a page mount and the fixture's other editor,
        // who has none, cannot stand in for them.
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $this->importCSVDataSet(__DIR__.'/Fixtures/module_link_users.csv');
        $backendUser = $this->setUpBackendUser(4);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $body = (string) $this->dispatch(['id' => '2'])->getBody();

        self::assertStringNotContainsString('pagemetrics-module-link', $body);
        // The rest of the row is untouched by that decision.
        self::assertStringContainsString('pagemetrics-tiles', $body);
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

    public function testRendersEmptyResponseWhenTheBackendUsersTsConfigDisablesAccess(): void
    {
        // Defense in depth: PageHeaderMetricsRendererTest covers the same
        // TSconfig flag stopping the placeholder from loading this route in
        // the first place, but the route itself is reachable directly, so
        // it has to enforce the same access decision independently.
        $this->writeSiteConfiguration('main', 1, 'https://example.com/');
        $backendUser = $this->setUpBackendUser(3);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $response = $this->dispatch(['id' => '2']);

        self::assertSame('', (string) $response->getBody());
    }

    /**
     * The response carries every tile the site's providers declare, and a
     * tile with no data of its own still renders a value, a badge and a
     * percent sign. An assertion against the whole body is satisfied by
     * those whatever the tile under test does, so anything about one tile
     * is asserted against that tile's own markup.
     */
    private static function tileMarkup(string $body, string $metricIdentifier): string
    {
        foreach (explode('<div class="pagemetrics-tile"', $body) as $tile) {
            if (str_contains($tile, 'data-metric="'.$metricIdentifier.'"')) {
                return $tile;
            }
        }

        self::fail(sprintf('No tile for metric identifier "%s" in the rendered response.', $metricIdentifier));
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

    private function writeSiteConfiguration(string $identifier, int $rootPageId, string $base, ?int $annotationStoragePid = null): void
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
            'pageMetrics' => [
                'providers' => ['matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 1, 'tokenAuth' => 'x']],
                'annotations' => null !== $annotationStoragePid ? ['storagePid' => $annotationStoragePid] : [],
            ],
        ]);
    }
}
