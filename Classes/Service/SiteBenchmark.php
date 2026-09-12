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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{BenchmarkBand, MetricPolarity};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition, RateMetricDefinition, SiteBenchmarkResult};
use KonradMichalik\Typo3PageMetrics\Persistence\{DailyMetricsRepository, SitePageUidRepository};
use TYPO3\CMS\Core\Site\Entity\Site;

use function array_key_exists;
use function array_values;
use function count;
use function intdiv;
use function round;
use function sort;

/**
 * SiteBenchmark.
 *
 * The typical page of a site, for the same metric and the same period a tile
 * is showing. A number on its own is not actionable: "31% bounce rate" says
 * nothing until you know whether the rest of the site sits at 20% or at 60%.
 *
 * Computed from the rows already in the fact table, so it costs one query per
 * metric and per period, no new source and no schema. The site's page-uid list
 * is held per instance because every tile of a render needs the same one, the
 * same reason ImportCoverage is not readonly either.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SiteBenchmark
{
    /**
     * Below this the median is the page looking at itself, or at the only
     * other page there is, and the comparison says nothing it does not
     * already know.
     */
    private const MINIMUM_PAGES_FOR_MEDIAN = 2;

    /**
     * A fifth of four pages is not a fifth. Bands need enough of a field to
     * divide before "the top fifth" describes anything.
     */
    private const MINIMUM_PAGES_FOR_BAND = 5;

    /** @var array<int, list<int>> */
    private array $sitePageUids = [];

    public function __construct(
        private readonly DailyMetricsRepository $dailyMetricsRepository,
        private readonly SitePageUidRepository $pageUidRepository,
        private readonly RateSeriesCalculator $rateSeriesCalculator,
    ) {}

    public function forCountMetric(Site $site, int $pageUid, int $languageId, MetricDefinition $metric, DateRange $range, int $pageValue): ?SiteBenchmarkResult
    {
        return $this->compute($site, $pageUid, $pageValue, self::bandApplies($metric->polarity), /** @param list<int> $pageUids */
            fn (array $pageUids): array => $this->dailyMetricsRepository->sumsByPage($pageUids, $languageId, $metric->identifier, $range));
    }

    /**
     * A rate's per-page value is sum(numerator) / sum(denominator) over the
     * whole period, never the mean of its daily ratios: a day with three
     * visits would otherwise weigh as much as a day with three hundred. That
     * is the same rule RateSeriesCalculator exists to keep, applied per page
     * instead of per site.
     *
     * A page whose denominator is zero is left out rather than counted as
     * zero. It has no rate at all, and feeding a zero into the median would
     * drag the typical page towards a value no page actually had - the same
     * judgement PageTrendCalculator makes when it decides "no data" on the
     * denominator.
     *
     * No band for a rate, deliberately; see bandApplies() for the rule that
     * covers this and the metrics where a rise is not an improvement.
     */
    public function forRateMetric(Site $site, int $pageUid, int $languageId, RateMetricDefinition $metric, DateRange $range, int $pageValue): ?SiteBenchmarkResult
    {
        return $this->compute($site, $pageUid, $pageValue, false, /** @param list<int> $pageUids */
            function (array $pageUids) use ($languageId, $metric, $range): array {
                $numerators = $this->dailyMetricsRepository->sumsByPage($pageUids, $languageId, $metric->numerator, $range);
                $denominators = $this->dailyMetricsRepository->sumsByPage($pageUids, $languageId, $metric->denominator, $range);

                $ratios = [];
                foreach ($denominators as $pageUid => $denominator) {
                    if (0 === $denominator) {
                        continue;
                    }

                    $ratios[$pageUid] = $this->rateSeriesCalculator->ratio($numerators[$pageUid] ?? 0, $denominator, $metric->unit);
                }

                return $ratios;
            });
    }

    /**
     * Whether "the top fifth" says anything for this metric.
     *
     * Only where a higher number is the better outcome. On a metric where
     * lower is better the phrase means its opposite, and on one with no
     * polarity at all - an average time on page, a crawler-hit count - it
     * means nothing in either direction. A phrase that flips per metric is
     * worse than no phrase, which is the same reason a rate never gets one.
     */
    private static function bandApplies(MetricPolarity $polarity): bool
    {
        return MetricPolarity::HigherIsBetter === $polarity;
    }

    /**
     * No memoisation of the result. Each metric is built exactly once per
     * render and the answer depends on the page's own value, so no two calls
     * in a request ever ask the same question, and a cache here would never be
     * read. The page-uid list is the part that does repeat, and that is cached.
     *
     * @param callable(list<int>): array<int, int> $collect
     */
    private function compute(Site $site, int $pageUid, int $pageValue, bool $withBand, callable $collect): ?SiteBenchmarkResult
    {
        $rootPageId = $site->getRootPageId();
        $pageUids = $this->sitePageUids[$rootPageId] ??= $this->pageUidRepository->descendantsOf($rootPageId);

        $collected = $collect($pageUids);
        $values = array_values($collected);
        $count = count($values);

        if ($count < self::MINIMUM_PAGES_FOR_MEDIAN) {
            return null;
        }

        // A page the importer has not reached yet is not in the field, and
        // ranking it against pages that are puts it at the bottom of one:
        // "this page is in the bottom fifth" beside a tile reading 0 reports
        // "not imported" as "worst on the site". The median still stands as a
        // statement about the site, so only the placement is withheld.
        $isInTheField = array_key_exists($pageUid, $collected);

        sort($values);

        // A field with no spread has no ranking in it. Every page is the
        // typical page, and any band would be an artefact of how ties are
        // broken rather than a fact about this one.
        $hasSpread = $values[0] !== $values[$count - 1];

        $median = $this->median($values);

        return new SiteBenchmarkResult(
            $median,
            $count,
            $withBand && $isInTheField && $hasSpread && $count >= self::MINIMUM_PAGES_FOR_BAND ? BenchmarkBand::of($pageValue, $median, $values) : null,
        );
    }

    /**
     * @param list<int> $sorted ascending, at least one entry
     */
    private function median(array $sorted): int
    {
        $count = count($sorted);
        $middle = intdiv($count, 2);

        // An even field has no middle element, so the two either side of the
        // gap are averaged - rounded, because every metric this compares is
        // a whole number in its own unit.
        return 0 === $count % 2
            ? (int) round(($sorted[$middle - 1] + $sorted[$middle]) / 2)
            : $sorted[$middle];
    }
}
