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
use KonradMichalik\Typo3PageMetrics\Service\{DayDetailBuilder, PageMetricsAccessChecker};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Http\HtmlResponse;

use function is_string;

/**
 * PageMetricsDayController.
 *
 * The panel behind a marker in the page module's sparkline: what happened on
 * that one day, and whether the marker changed anything.
 *
 * Alongside PageMetricsTilesController rather than a mode on it, and built
 * exactly like it: a repeatable GET returning markup, everything expensive
 * on this side of the request, and the same access decision enforced here
 * rather than left to the UI that opens it. Both the page uid and the date
 * arrive from the request and neither is trusted: the uid goes through the
 * same PAGE_SHOW check the tiles route applies, and the date has to survive
 * a round trip through its own format before it reaches a query.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PageMetricsDayController
{
    public function __construct(
        private DayDetailBuilder $dayDetailBuilder,
        private BackendViewFactory $backendViewFactory,
        private PageMetricsAccessChecker $accessChecker,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->accessChecker->hasAccess()) {
            // Same reason as on the tiles route: this one is reachable
            // directly, so the User TSconfig switch has to be honoured here
            // and not only where the placeholder is injected.
            return new HtmlResponse('');
        }

        $queryParams = $request->getQueryParams();
        $pageId = (int) ($queryParams['id'] ?? 0);
        $date = $this->parseDate($queryParams['date'] ?? null);
        $includeSubpages = '1' === ($queryParams['includeSubpages'] ?? '0');

        if (null === $date) {
            return new HtmlResponse('');
        }

        $detail = $this->dayDetailBuilder->build($pageId, $date, $includeSubpages);

        if (null === $detail) {
            return new HtmlResponse('');
        }

        $view = $this->backendViewFactory->create($request, ['konradmichalik/typo3-page-metrics']);
        $view->assignMultiple([
            'date' => $detail->date,
            'annotations' => $detail->annotations,
            'metrics' => $detail->metrics,
            'comparisons' => $detail->comparisons,
        ]);

        return new HtmlResponse($view->render('PageHeader/Day'));
    }

    /**
     * createFromFormat() is lenient about overflow on its own - "2026-13-45"
     * becomes a date in 2027 rather than an error - so the round trip back
     * through the same format is what actually rejects a made-up date
     * instead of quietly answering for a different one.
     */
    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
