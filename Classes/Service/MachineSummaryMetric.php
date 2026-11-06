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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricGroup, MetricPolarity, MetricUnit};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition};
use KonradMichalik\Typo3PageMetrics\Persistence\DailyMetricsRepository;
use KonradMichalik\Typo3PageMetrics\Provider\{MetricDefinitionCollector, MetricProviderInterface};
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\Entity\Site;

use function array_filter;
use function array_values;

/**
 * MachineSummaryMetric.
 *
 * The same total PageMetricsViewDataBuilder's tile calls "Bot traffic" -
 * every one of a site's MetricGroup::Machine metrics added together - made
 * available to the chart widget as a synthetic, selectable metric rather
 * than only ever being folded live inside one tile.
 *
 * Kept apart from SiteMetricsOverviewBuilder rather than inlined into it:
 * that class backs four widgets already and every branch this adds to
 * resolving, listing and charting metrics only exists for the chart's own
 * "Bot traffic" choice.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MachineSummaryMetric
{
    private const LL = 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:';

    /**
     * The same identifier prefix PageMetricsViewDataBuilder's tile uses (see
     * its MACHINE_SUMMARY_IDENTIFIER), so the chart's total and the tile's
     * own summary read as the one thing they are - suffixed with the
     * provider's own identifier for the same reason as there: two providers
     * each declaring machine metrics would otherwise produce two
     * indistinguishable summary entries.
     */
    private const IDENTIFIER = 'machine.summary';
    private const ICON = 'tabler-robot';
    private const COLOR = '#868e96';

    public function __construct(
        private MetricDefinitionCollector $metricDefinitionCollector,
        private DailyMetricsRepository $dailyMetricsRepository,
    ) {}

    public function identifierFor(MetricProviderInterface $provider): string
    {
        return self::IDENTIFIER.'.'.$provider->getIdentifier();
    }

    /**
     * Null where this site's provider has nothing to sum - a provider
     * declaring no MetricGroup::Machine metrics at all, or one a site's own
     * `metrics` allow list has filtered down to none.
     */
    public function definitionFor(MetricProviderInterface $provider, Site $site): ?MetricDefinition
    {
        if ([] === $this->machineDefinitionsFor($provider, $site)) {
            return null;
        }

        $languageService = $this->getLanguageService();

        return new MetricDefinition(
            $this->identifierFor($provider),
            $languageService->sL(self::LL.'metric.machine.label'),
            $languageService->sL(self::LL.'metric.machine.description'),
            self::ICON,
            MetricUnit::Count,
            // Only ever compared against other MetricGroup::Machine
            // definitions, which primaryFor() already excludes wholesale -
            // this identifier never reaches a list sortOrder decides.
            0,
            self::COLOR,
            // Neutral throughout: more crawler traffic is neither an
            // achievement nor a failure, the same reading the tile's own
            // summary gives it.
            MetricPolarity::Neutral,
            MetricGroup::Machine,
        );
    }

    /**
     * The chart's own daily version of buildMachineSummaryTile()'s
     * foldBucket() loop in PageMetricsViewDataBuilder - the same elementwise
     * sum across this site's bot.* metrics, kept apart by day instead of
     * collapsed into one period total.
     *
     * @param list<int> $pageUids
     *
     * @return array<string, int> Y-m-d => summed value, zero-filled the same way a single metric's own siteSeries() call already is
     */
    public function seriesFor(MetricProviderInterface $provider, Site $site, array $pageUids, int $languageId, DateRange $range): array
    {
        $series = [];

        foreach ($this->machineDefinitionsFor($provider, $site) as $definition) {
            foreach ($this->dailyMetricsRepository->siteSeries($pageUids, $languageId, $definition->identifier, $range) as $date => $value) {
                $series[$date] = ($series[$date] ?? 0) + $value;
            }
        }

        return $series;
    }

    /**
     * @return list<MetricDefinition>
     */
    private function machineDefinitionsFor(MetricProviderInterface $provider, Site $site): array
    {
        return array_values(array_filter(
            $this->metricDefinitionCollector->forSite($provider, $site),
            static fn (MetricDefinition $definition): bool => MetricGroup::Machine === $definition->group,
        ));
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
