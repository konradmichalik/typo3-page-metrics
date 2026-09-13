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
     * @param list<PeriodComparisonRow> $rows
     * @param list<ComparisonBaseline>  $baselineOptions
     */
    public function __construct(
        public ComparisonBaseline $baseline,
        public int $periodDays,
        public string $currentRangeLabel,
        public string $referenceRangeLabel,
        public array $rows,
        public array $baselineOptions,
    ) {}
}
