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
 * PeriodComparison.
 *
 * The compare view's whole payload: which two periods were held against each
 * other, a row per metric, and the baselines the reader may switch to.
 *
 * The options travel with the result rather than being listed in the
 * template, so the picker cannot offer a baseline the builder does not
 * honour.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PeriodComparison
{
    /**
     * $chart is the payload sparkline.js already reads for the dashboard
     * chart widget, so the compare view draws through that one renderer
     * instead of carrying chart code of its own. Two series of the same
     * metric, keyed by the day's position in the period rather than by its
     * date: the two ranges cover different dates, and day 1 against day 1 is
     * the only alignment a reader can take a comparison off. Empty where the
     * site declares no counted metric to draw, and $chartLabel names the one
     * that is drawn so the heading says which of the table's rows it is.
     *
     * @param list<PeriodComparisonRow>                                                                                   $rows
     * @param list<ComparisonBaseline>                                                                                    $baselineOptions
     * @param list<array{label: string, color: string, unit: string, subordinate: bool, values: array<string, int|null>}> $chart
     * @param bool                                                                                                        $periodsOverlap  whether the two periods share days, which is why there
     *                                                                                                                                     is no chart: only the start of the baseline moves back, so a
     *                                                                                                                                     90 day period against one month earlier holds 59 of its own
     *                                                                                                                                     days against itself
     */
    public function __construct(
        public ComparisonBaseline $baseline,
        public int $periodDays,
        public string $currentRangeLabel,
        public string $referenceRangeLabel,
        public array $rows,
        public array $baselineOptions,
        public array $chart,
        public string $chartLabel,
        public bool $periodsOverlap,
    ) {}
}
