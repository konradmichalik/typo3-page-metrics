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
use KonradMichalik\Typo3PageMetrics\Controller\Ajax\PageMetricsExportController;
use KonradMichalik\Typo3PageMetrics\Persistence\DailyMetricsRepository;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function array_pop;
use function explode;
use function sprintf;
use function str_starts_with;
use function substr;

/**
 * PageMetricsExportControllerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class PageMetricsExportControllerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/be_users.csv');
        $this->useBackendUser(1);
        $this->today = new DateTimeImmutable('today');
        $this->writeSiteConfiguration();
    }

    public function testRefusesAReaderTheUserTsconfigSwitchTurnedTheTilesOffFor(): void
    {
        // The same switch that hides the tiles. This route is reachable by
        // typing it, so it has to be decided here and not by the docheader
        // that offers the link.
        $this->useBackendUser(3);

        self::assertSame(403, $this->dispatch(['id' => '2'])->getStatusCode());
    }

    public function testRefusesAPageTheReaderMayNotSeeInThePageTree(): void
    {
        $this->upsert(3, 'matomo.pageviews', 40);
        $this->useBackendUser(2);

        self::assertSame(403, $this->dispatch(['id' => '3'])->getStatusCode());
    }

    public function testRefusesARequestThatNamesNoPage(): void
    {
        self::assertSame(400, $this->dispatch([])->getStatusCode());
    }

    public function testOffersTheFileAsADownloadRatherThanAsSomethingToRender(): void
    {
        $response = $this->dispatch(['id' => '2', 'view' => 'page', 'period' => '7']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame(
            sprintf('attachment; filename="page-metrics-page-page-2-%s_%s.csv"', $this->today->modify('-6 days')->format('Y-m-d'), $this->today->format('Y-m-d')),
            $response->getHeaderLine('Content-Disposition'),
        );
    }

    public function testThePageExportCarriesTheTilesRawFiguresAndNotTheStringsTheyAreShownAs(): void
    {
        $this->upsert(2, 'matomo.time_spent_seconds', 366);
        $this->upsert(2, 'matomo.visits', 2);

        $rows = $this->rowsOf($this->dispatch(['id' => '2', 'view' => 'page', 'period' => '7']));

        self::assertSame('"metric";"unit";"value";"previous_value";"delta_percent";"comparison"', $rows[0]);
        self::assertContains('"matomo.avg_time_on_page";"seconds";183;0;;"new"', $rows);
    }

    public function testTheCompareExportHoldsBothPeriodsAsNumbers(): void
    {
        $this->upsert(2, 'matomo.pageviews', 40);
        $this->upsert(2, 'matomo.pageviews', 100, '-7 days');

        $rows = $this->rowsOf($this->dispatch(['id' => '2', 'view' => 'compare', 'period' => '7', 'compareBaseline' => 'previous']));

        self::assertSame('"metric";"unit";"value";"reference_value";"difference";"delta_percent";"significant";"comparison"', $rows[0]);
        self::assertContains('"matomo.pageviews";"count";40;100;-60;-60;1;"ok"', $rows);
    }

    public function testTheFileLeadsWithAByteOrderMarkAndSeparatesWithSemicolons(): void
    {
        $body = (string) $this->dispatch(['id' => '2', 'view' => 'page', 'period' => '7'])->getBody();

        self::assertTrue(str_starts_with($body, "\xEF\xBB\xBF"));
        self::assertStringContainsString('";"', $body);
    }

    public function testAPeriodNobodyCanSelectInTheModuleFallsBackToTheDefaultOne(): void
    {
        // The module offers three periods. This route is reached without its
        // docheader, so a crafted link must not export a window that has no
        // view behind it.
        $response = $this->dispatch(['id' => '2', 'view' => 'page', 'period' => '900']);

        self::assertStringContainsString($this->today->modify('-29 days')->format('Y-m-d'), $response->getHeaderLine('Content-Disposition'));
    }

    public function testTheSiteWideExportIsOneRowPerPageWithAColumnPerMetric(): void
    {
        $this->upsert(1, 'matomo.pageviews', 10);
        $this->upsert(2, 'matomo.pageviews', 40);

        $rows = $this->rowsOf($this->dispatch(['id' => '2', 'view' => 'pages', 'period' => '7']));

        self::assertSame(
            '"page_uid";"page_title";"matomo.pageviews";"matomo.visits";"matomo.avg_time_on_page";"matomo.bounce_rate";"trend_metric";"trend_delta_percent";"comparison";"days_since_change";"decaying"',
            $rows[0],
        );
        self::assertSame('2;"About";40;0;;;"matomo.pageviews";;"new";;0', $rows[1]);
        self::assertSame('1;"Home";10;0;;;"matomo.pageviews";;"new";;0', $rows[2]);
    }

    public function testTheSiteWideExportReachesPastThePagerTheViewIsLimitedTo(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/many-pages.csv');

        foreach (range(10, 40) as $uid) {
            $this->upsert($uid, 'matomo.pageviews', $uid);
        }

        // Thirty-one pages is more than the twenty-five the table shows at
        // once. A pager belongs to a screen, not to a file.
        self::assertCount(32, $this->rowsOf($this->dispatch(['id' => '2', 'view' => 'pages', 'period' => '7'])));
    }

    public function testTheSiteWideExportFollowsTheSortTheReaderChose(): void
    {
        $this->upsert(1, 'matomo.pageviews', 10);
        $this->upsert(2, 'matomo.pageviews', 40);

        $ascending = $this->rowsOf($this->dispatch(['id' => '2', 'view' => 'pages', 'period' => '7', 'direction' => 'asc']));

        self::assertSame('1;"Home";10;0;;;"matomo.pageviews";;"new";;0', $ascending[1]);
    }

    /**
     * @return list<string>
     */
    private function rowsOf(ResponseInterface $response): array
    {
        $lines = explode("\r\n", substr((string) $response->getBody(), 3));
        array_pop($lines);

        return $lines;
    }

    private function upsert(int $pageUid, string $metric, int $value, string $ago = 'today'): void
    {
        $date = 'today' === $ago ? $this->today : $this->today->modify($ago);
        $this->get(DailyMetricsRepository::class)->upsert($pageUid, 0, 'matomo', $date, $metric, $value);
    }

    private function useBackendUser(int $uid): void
    {
        $backendUser = $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function dispatch(array $queryParams): ResponseInterface
    {
        return $this->get(PageMetricsExportController::class)->handleRequest($this->buildRequest($queryParams));
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function buildRequest(array $queryParams): ServerRequestInterface
    {
        $route = new Route('/page-metrics/export', ['packageName' => 'konradmichalik/typo3-page-metrics']);
        $request = (new ServerRequest('https://example.com/typo3/ajax/page-metrics/export'))
            ->withQueryParams($queryParams)
            ->withAttribute('route', $route)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
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
