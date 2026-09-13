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

namespace KonradMichalik\Typo3PageMetrics\Controller\Ajax;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\ModuleView;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{ComparisonBaseline, CsvExport, DateRange, PeriodComparison, SitePagesColumn};
use KonradMichalik\Typo3PageMetrics\Persistence\SitePageUidRepository;
use KonradMichalik\Typo3PageMetrics\Service\{CsvExportBuilder, CsvStreamWriter, PageMetricsAccessChecker, PageMetricsViewDataBuilder, PeriodComparisonBuilder, SiteMetricValueReader, SitePagesTableBuilder};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Throwable;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

use function in_array;
use function is_array;
use function is_string;
use function sprintf;

/**
 * PageMetricsExportController.
 *
 * "Can you send me that", answered with a file instead of a screenshot.
 *
 * A backend AJAX route rather than a mode on the module's own route, and
 * reached by a plain link in the docheader whose href the module builds with
 * UriBuilder, which signs it. The module route was the obvious alternative
 * and is the wrong one: BackendModuleValidator merges any query parameter
 * named like a moduleData key into that user's stored module state before
 * the request is dispatched, so a download link carrying `period` and `view`
 * would not describe one download, it would rewrite what the reader sees the
 * next time they open the module. Nothing on this route touches moduleData,
 * which is also why every piece of view state it needs is spelled out in the
 * query string rather than read back from storage.
 *
 * Access is decided here and not by the surface that offers the link, for
 * the same reason PageMetricsDayController decides it: a route is reachable
 * by typing it. `inheritAccessFromModule` covers TYPO3's own module
 * permission, PageMetricsAccessChecker covers the User TSconfig switch, and
 * PAGE_SHOW on the page uid from the query string covers the rest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PageMetricsExportController
{
    /**
     * The same three the module's docheader offers. Named again rather than
     * read from the module controller because this route is reached without
     * it, and an unvalidated period from a query string would let a crafted
     * link export a window nobody can see on screen.
     *
     * @var list<int>
     */
    private const PERIOD_OPTIONS = [7, 30, 90];

    private const DEFAULT_PERIOD = 30;

    public function __construct(
        private PageMetricsAccessChecker $accessChecker,
        private PageMetricsViewDataBuilder $viewDataBuilder,
        private PeriodComparisonBuilder $periodComparisonBuilder,
        private SiteMetricValueReader $valueReader,
        private SitePageUidRepository $pageUidRepository,
        private SiteFinder $siteFinder,
        private SitePagesTableBuilder $sitePagesTableBuilder,
        private CsvExportBuilder $exportBuilder,
        private CsvStreamWriter $streamWriter,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->accessChecker->hasAccess()) {
            return new Response('php://temp', 403);
        }

        $queryParams = $request->getQueryParams();
        $pageId = (int) ($queryParams['id'] ?? 0);

        if ($pageId < 1) {
            return new Response('php://temp', 400);
        }

        if (false === BackendUtility::readPageAccess($pageId, $this->getBackendUser()->getPagePermsClause(Permission::PAGE_SHOW))) {
            // The page uid arrives from the request, so this is what stands
            // between a hand-built URL and a file of another page's numbers.
            return new Response('php://temp', 403);
        }

        $periodDays = $this->period($queryParams['period'] ?? null);
        $export = match (ModuleView::tryFrom(self::stringOf($queryParams, 'view')) ?? ModuleView::default()) {
            ModuleView::Page => $this->pageExport($pageId, $periodDays, self::flagOf($queryParams, 'includeSubpages')),
            ModuleView::Compare => $this->comparisonExport($pageId, $periodDays, self::stringOf($queryParams, 'compareBaseline'), self::flagOf($queryParams, 'includeSubpages')),
            ModuleView::Pages => $this->sitePagesExport($pageId, $periodDays, self::stringOf($queryParams, 'sort'), self::stringOf($queryParams, 'direction')),
        };

        if (null === $export) {
            return new Response('php://temp', 404);
        }

        $stream = $this->streamWriter->write($export);

        return new Response($stream, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            // The filename is built from a view name, a page uid or a
            // slugged site identifier and two dates, so nothing an editor
            // can type reaches this header unescaped (see CsvExport::named).
            'Content-Disposition' => sprintf('attachment; filename="%s"', $export->filename),
            'Content-Length' => (string) ($stream->getSize() ?? 0),
            // Nothing here is markup, and a browser that decides otherwise
            // would be rendering editor-authored page titles on the
            // backend's own origin.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function pageExport(int $pageId, int $periodDays, bool $includeSubpages): CsvExport
    {
        // The view's own call, with the view's own arguments. Language 0
        // because every surface of this extension is single-language today
        // and the module makes the same choice.
        $viewData = $this->viewDataBuilder->build($pageId, 0, $periodDays, $includeSubpages);

        return $this->exportBuilder->forPageView($viewData, self::currentRange($periodDays));
    }

    /**
     * The rows, their order and the verdict on whether the two periods may
     * be held against each other all come from PeriodComparisonBuilder. Only
     * the figures are read again, because PeriodComparisonRow carries them
     * as formatted strings and a file has to say 183 rather than "3:03".
     * Joined back by metric identifier, so the builder stays the only thing
     * that decides what a row of this file is.
     */
    private function comparisonExport(int $pageId, int $periodDays, string $baselineKey, bool $includeSubpages): ?CsvExport
    {
        $comparison = $this->periodComparisonBuilder->build($pageId, 0, $periodDays, $baselineKey, $includeSubpages, false);
        $site = $this->resolveSite($pageId);

        if (null === $comparison || null === $site) {
            return null;
        }

        $currentRange = self::currentRange($periodDays);
        $referenceRange = ComparisonBaseline::fromKey($baselineKey)->reference($currentRange, $periodDays);
        $pageUids = $includeSubpages ? $this->pageUidRepository->descendantsOf($pageId) : [$pageId];

        return $this->exportBuilder->forComparison(
            $comparison,
            $pageId,
            $currentRange,
            $this->valueReader->totals($site, $pageUids, 0, $currentRange),
            // Not asked for at all where no row may use it, exactly as the
            // builder refuses to sum a window nobody imported.
            self::hasComparableRow($comparison) ? $this->valueReader->totals($site, $pageUids, 0, $referenceRange) : [],
        );
    }

    /**
     * Every row of the site-wide table rather than the twenty-five on
     * screen, because a pager belongs to a screen and not to a file. The
     * rows, their order, their period and the PAGE_SHOW filter all come out
     * of SitePagesTableBuilder::stream(), which is the same pass build()
     * makes for the view: an export is only "what the view shows" for as
     * long as nothing here has an opinion of its own about the order.
     */
    private function sitePagesExport(int $pageId, int $periodDays, string $sortKey, string $sortDirection): ?CsvExport
    {
        $site = $this->resolveSite($pageId);

        if (null === $site) {
            return null;
        }

        $columns = $this->sitePagesTableBuilder->columnsOf($site);

        if ([] === $columns) {
            // No metric provider is configured for this site, so the view
            // shows no table and there is nothing to be a file.
            return null;
        }

        return $this->exportBuilder->forSitePages(
            $site->getIdentifier(),
            $columns,
            self::trendMetricOf($columns, $sortKey),
            $this->sitePagesTableBuilder->stream($site, 0, $periodDays, $sortKey, $sortDirection),
            self::currentRange($periodDays),
        );
    }

    /**
     * Which metric the movement column is about. Not recomputed from the
     * request: it is the builder's own documented fallback, that a sort key
     * naming no metric column (the empty string a reader who never sorted
     * arrives with, and the decay order, which is not a metric) leaves the
     * trend on the site's first metric.
     *
     * @param non-empty-list<SitePagesColumn> $columns
     */
    private static function trendMetricOf(array $columns, string $sortKey): string
    {
        foreach ($columns as $column) {
            if ($column->key === $sortKey) {
                return $column->key;
            }
        }

        return $columns[0]->key;
    }

    private static function hasComparableRow(PeriodComparison $comparison): bool
    {
        foreach ($comparison->rows as $row) {
            if (!$row->hasNoComparison) {
                return true;
            }
        }

        return false;
    }

    /**
     * The same arithmetic every builder of this extension performs, so "this
     * period" names the same days in the file as on the screen it came from.
     */
    private static function currentRange(int $periodDays): DateRange
    {
        $today = new DateTimeImmutable('today');

        return new DateRange($today->modify(sprintf('-%d days', $periodDays - 1)), $today);
    }

    private function period(mixed $value): int
    {
        $period = (int) (is_array($value) ? 0 : $value);

        return in_array($period, self::PERIOD_OPTIONS, true) ? $period : self::DEFAULT_PERIOD;
    }

    /**
     * @param array<mixed> $queryParams
     */
    private static function stringOf(array $queryParams, string $key): string
    {
        $value = $queryParams[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<mixed> $queryParams
     */
    private static function flagOf(array $queryParams, string $key): bool
    {
        return '1' === self::stringOf($queryParams, $key);
    }

    private function resolveSite(int $pageId): ?Site
    {
        try {
            return $this->siteFinder->getSiteByPageId($pageId);
        } catch (Throwable) {
            return null;
        }
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
