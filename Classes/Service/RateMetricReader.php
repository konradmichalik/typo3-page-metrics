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

namespace KonradMichalik\Typo3PageMetrics\Service;

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, RateMetricDefinition, RateTotals};
use KonradMichalik\Typo3PageMetrics\Persistence\DailyMetricsRepository;

/**
 * RateMetricReader.
 *
 * Reading a rate metric over a period was written out in five services
 * before this existed, in three spellings: a private sum() over
 * siteSeries(), an inline array_sum() over sumsByPage(), and the division
 * spelled out at the call site each time. Every view that showed a rate
 * wrote the next copy.
 *
 * It answers with the pair and not the quotient. A caller that only wants
 * the number reads ->ratio; a caller that has to tell "this metric was never
 * recorded" from "this ratio is genuinely zero" reads ->denominator, which
 * is the distinction an extraction returning a single float would have
 * quietly removed everywhere at once (see RateTotals).
 *
 * The division itself stays in RateSeriesCalculator, which is deliberately
 * dependency-free and knows nothing about where numbers come from. This is
 * the piece that knows where they come from and nothing about how they
 * divide.
 *
 * Two shapes, not one with a switch: a site figure is one pair for the whole
 * range, a per-page figure is a pair per page, and they are different
 * queries returning different types for different views.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RateMetricReader
{
    public function __construct(
        private DailyMetricsRepository $dailyMetricsRepository,
        private RateSeriesCalculator $rateSeriesCalculator,
    ) {}

    /**
     * One pair for every page in $pageUids taken together.
     *
     * @param list<int> $pageUids
     */
    public function forSite(array $pageUids, int $languageId, RateMetricDefinition $metric, DateRange $range): RateTotals
    {
        $numerator = $this->sum($pageUids, $languageId, $metric->numerator, $range);
        $denominator = $this->sum($pageUids, $languageId, $metric->denominator, $range);

        return new RateTotals($numerator, $denominator, $this->rateSeriesCalculator->ratio($numerator, $denominator, $metric->unit));
    }

    /**
     * One pair per page, keyed on the pages the denominator was recorded
     * for. A page carrying only a numerator is absent rather than present
     * with a zero: it is a ratio nothing was divided by, and the callers
     * read the key set itself as "this page has a value at all".
     *
     * @param list<int> $pageUids
     *
     * @return array<int, RateTotals> page uid => its totals over the range
     */
    public function byPage(array $pageUids, int $languageId, RateMetricDefinition $metric, DateRange $range): array
    {
        $numerators = $this->dailyMetricsRepository->sumsByPage($pageUids, $languageId, $metric->numerator, $range);
        $denominators = $this->dailyMetricsRepository->sumsByPage($pageUids, $languageId, $metric->denominator, $range);

        $totals = [];
        foreach ($denominators as $pageUid => $denominator) {
            $numerator = $numerators[$pageUid] ?? 0;
            $totals[$pageUid] = new RateTotals($numerator, $denominator, $this->rateSeriesCalculator->ratio($numerator, $denominator, $metric->unit));
        }

        return $totals;
    }

    /**
     * @param list<int> $pageUids
     */
    private function sum(array $pageUids, int $languageId, string $metric, DateRange $range): int
    {
        return array_sum($this->dailyMetricsRepository->siteSeries($pageUids, $languageId, $metric, $range));
    }
}
