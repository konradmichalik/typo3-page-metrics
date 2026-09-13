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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricGroup;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricTotal};
use KonradMichalik\Typo3PageMetrics\Persistence\DailyMetricsRepository;
use KonradMichalik\Typo3PageMetrics\Provider\{MetricDefinitionCollector, MetricProviderInterface};
use TYPO3\CMS\Core\Site\Entity\Site;

use function array_sum;

/**
 * SiteMetricValueReader.
 *
 * Every metric a site declares, summed over one period, as numbers.
 *
 * It exists for the CSV export, and only because the module's own value
 * objects hand their figures on as formatted strings. A template wants
 * "3:03"; a file has to say 183 and has to say which unit that is. Rather
 * than widening the display objects with a second representation of the
 * same number, the export asks for the figures again, over the very ranges
 * the view was built for, and joins them to the view's rows by metric
 * identifier. The view therefore stays the only thing that decides which
 * rows exist, in what order, and whether a period may be held against
 * another; this only fills in what those rows are worth.
 *
 * The arithmetic is the one PeriodComparisonBuilder performs: a count is
 * summed over the period, a rate is sum(numerator) over sum(denominator)
 * for the whole period through RateMetricReader, never an average of
 * daily ratios.
 *
 * Machine traffic is left out, as it is on every surface of this extension:
 * a crawler bucket is folded into one summary tile and is never a row of
 * its own, so no export has a row to fill.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class SiteMetricValueReader
{
    /**
     * @param iterable<MetricProviderInterface> $providers
     */
    public function __construct(
        private DailyMetricsRepository $dailyMetricsRepository,
        private RateMetricReader $rateMetricReader,
        private MetricDefinitionCollector $metricDefinitionCollector,
        private iterable $providers,
    ) {}

    /**
     * @param list<int> $pageUids
     *
     * @return array<string, MetricTotal> metric identifier => its total over the range
     */
    public function totals(Site $site, array $pageUids, int $languageId, DateRange $range): array
    {
        $totals = [];

        foreach ($this->metricDefinitionCollector->supporting($this->providers, $site) as $provider) {
            foreach ($this->metricDefinitionCollector->forSite($provider, $site) as $definition) {
                if (MetricGroup::Machine === $definition->group) {
                    continue;
                }

                $totals[$definition->identifier] = new MetricTotal(
                    $definition->unit,
                    $this->sum($pageUids, $languageId, $definition->identifier, $range),
                );
            }

            foreach ($provider->getRateMetricDefinitions() as $definition) {
                $totals[$definition->identifier] = new MetricTotal($definition->unit, $this->rateMetricReader->forSite($pageUids, $languageId, $definition, $range)->ratio);
            }
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
