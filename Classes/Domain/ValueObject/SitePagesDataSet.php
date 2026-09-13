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

/**
 * SitePagesDataSet.
 *
 * Everything one pass over the site's pages produced, before anyone decides
 * whether it becomes a screen of the table or a file.
 *
 * It exists because the rendered view and the export have to agree, and the
 * only way two surfaces agree about an ordering is by not each having one.
 * The row order is decided once, here, and both readers walk the same list:
 * an export that sorted its own way would stop being "what the view shows",
 * which is the one thing it is asked for.
 *
 * Not an @api type. It carries this builder's working state and its shape
 * follows whatever the two surfaces happen to need.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class SitePagesDataSet
{
    /**
     * @param list<MetricDefinition|RateMetricDefinition> $metrics        every column of the table, in the provider's own order
     * @param array<string, array<int, int>>              $currentValues  metric identifier => (page uid => raw value)
     * @param array<int, int>                             $previousValues page uid => the sorted metric's raw value in the period before
     * @param array<int, int>                             $ages           page uid => whole days since the page was last changed
     * @param array<int, bool>                            $decaying       page uid => whether it earned the decay verdict
     * @param list<int>                                   $pageUids       every row of the result, already in the order both surfaces show
     */
    public function __construct(
        public array $metrics,
        public MetricDefinition|RateMetricDefinition $sortMetric,
        public string $sortKey,
        public string $sortDirection,
        public DateRange $currentRange,
        public DateRange $previousRange,
        public bool $previousPeriodCovered,
        public array $currentValues,
        public array $previousValues,
        public array $ages,
        public array $decaying,
        public array $pageUids,
    ) {}
}
