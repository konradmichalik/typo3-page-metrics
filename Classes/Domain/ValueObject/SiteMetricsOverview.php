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

namespace KonradMichalik\Typo3PageMetrics\Domain\ValueObject;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricUnit;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * SiteMetricsOverview.
 *
 * The site-wide counterpart to PageMetricsViewData - one build() call per
 * site backs all four dashboard widgets (chart, top pages, not-found pages,
 * annotations), the same way PageMetricsViewData backs the PageHeader tiles
 * and the tiles AJAX controller.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class SiteMetricsOverview
{
    /**
     * @param array<string, int>         $chartSeries           Y-m-d => summed value, covers the same period as topPages/unresolvedUrls
     * @param list<TopPage>              $topPages
     * @param list<UnresolvedUrlSummary> $unresolvedUrls
     * @param list<Annotation>           $annotations
     * @param list<ChartSeries>          $additionalChartSeries metrics overlaid on the chart alongside the primary one (see PageMetricsChartWidget's metric2 setting) - empty for every other site-metrics widget
     */
    public function __construct(
        public Site $site,
        public bool $hasSource,
        public string $chartLabel,
        public string $chartIcon,
        public string $chartColor,
        public MetricUnit $chartUnit,
        public array $chartSeries,
        public array $topPages,
        public array $unresolvedUrls,
        public array $annotations,
        public array $additionalChartSeries = [],
    ) {}
}
