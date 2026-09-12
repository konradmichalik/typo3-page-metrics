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

namespace KonradMichalik\Typo3PageMetrics\Provider\Matomo;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricGroup, MetricPolarity, MetricUnit};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition, MetricSignificance, RateMetricDefinition, UrlMetric};
use KonradMichalik\Typo3PageMetrics\Provider\SiteAwareMetricProviderInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

use function is_array;
use function is_string;
use function rawurlencode;
use function rtrim;
use function sprintf;

/**
 * MatomoMetricProvider.
 *
 * Calls Actions.getPageUrls with period=day once per calendar day in the
 * range, never period=range: UrlMetric->date needs one concrete day per
 * row, and per-day granularity is worth the extra API round trips since
 * this runs from a scheduled import, not a live per-request call. Uses
 * flat=1 so Matomo returns an already-flattened page table instead of a
 * tree needing manual flattening.
 *
 * Site-aware because of goals: the fixed metrics below exist on every Matomo
 * site, a goal only where one was configured (see MatomoGoalMetrics).
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MatomoMetricProvider implements SiteAwareMetricProviderInterface
{
    private const IDENTIFIER = 'matomo';

    /**
     * Below this many views a movement is arithmetic, not news: a page going
     * from two views to four has doubled and means nothing. Paired with the
     * relative threshold, so a small page that genuinely doubled still has to
     * clear both. Deliberately half of what Matomo's Generated Insights uses,
     * because a TYPO3 site is usually smaller than the Matomo Cloud volumes
     * that figure was calibrated on.
     */
    private const MOVEMENT_FLOOR_VIEWS = 10;

    public function __construct(
        private MatomoConfigurationResolver $configurationResolver,
        private MatomoApiClient $apiClient,
        private MatomoGoalMetrics $goalMetrics,
    ) {}

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function supports(Site $site): bool
    {
        return null !== $this->configurationResolver->resolve($site);
    }

    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition('matomo.pageviews', 'Page views', 'The number of times this page was viewed in the selected period.', 'tabler-eye-search', MetricUnit::Count, 10, '#0969da', MetricPolarity::HigherIsBetter, MetricGroup::Audience, new MetricSignificance(0.1, self::MOVEMENT_FLOOR_VIEWS)),
            new MetricDefinition('matomo.visits', 'Visits', 'The number of visits that included this page in the selected period.', 'tabler-chart-dots', MetricUnit::Count, 20, '#8250df', MetricPolarity::HigherIsBetter, MetricGroup::Audience, new MetricSignificance(0.1, self::MOVEMENT_FLOOR_VIEWS)),
        ];
    }

    public function getSiteMetricDefinitions(Site $site): array
    {
        return $this->goalMetrics->definitionsFor($site);
    }

    public function getRateMetricDefinitions(): array
    {
        return [
            new RateMetricDefinition(
                'matomo.avg_time_on_page',
                'Avg. time on page',
                'The average time visitors spent on this page in the selected period.',
                'tabler-clock',
                MetricUnit::Seconds,
                30,
                '#bf8700',
                'matomo.time_spent_seconds',
                'matomo.visits',
                // Neither direction is an improvement on its own: a long
                // read is a good sign on an article and a bad one on a
                // contact page, and this provider cannot know which it is.
                MetricPolarity::Neutral,
            ),
            new RateMetricDefinition(
                'matomo.bounce_rate',
                'Bounce rate',
                'The share of visits that started on this page and left without further interaction.',
                'tabler-door-exit',
                MetricUnit::Percent,
                40,
                '#cf222e',
                'matomo.entry_bounces',
                'matomo.entries',
                MetricPolarity::LowerIsBetter,
                // Percentage points, not percent: a bounce rate moving from
                // 30% to 33% moved by 3, and under that it is the sort of
                // wobble a handful of visits produces on its own. The figure
                // Matomo's Generated Insights uses for the same metric.
                new MetricSignificance(0.1, 3),
            ),
        ];
    }

    public function getExternalLink(Site $site, string $pageUrl, DateRange $range): ?string
    {
        $configuration = $this->configurationResolver->resolve($site);

        if (null === $configuration) {
            return null;
        }

        $dateRange = sprintf('%s,%s', $range->from->format('Y-m-d'), $range->until->format('Y-m-d'));
        $segment = rawurlencode(sprintf('pageUrl==%s', $pageUrl));

        return sprintf(
            '%s/index.php?module=CoreHome&action=index&idSite=%d&period=range&date=%s#?idSite=%d&period=range&date=%s&category=General_Actions&subcategory=General_Pages&segment=%s',
            rtrim($configuration->instanceUrl, '/'),
            $configuration->idSite,
            $dateRange,
            $configuration->idSite,
            $dateRange,
            $segment,
        );
    }

    public function fetch(Site $site, DateRange $range): iterable
    {
        $configuration = $this->configurationResolver->resolve($site);

        if (null === $configuration) {
            return;
        }

        $goals = $this->goalMetrics->goalsFor($site);
        $goalParameters = $this->goalMetrics->requestParameters($goals);

        foreach ($range->days() as $day) {
            $rows = $this->apiClient->request($configuration, [
                'method' => 'Actions.getPageUrls',
                'period' => 'day',
                'date' => $day->format('Y-m-d'),
                'flat' => 1,
                // Matomo's Reporting API defaults filter_limit to 100; -1 is
                // the documented "return all rows" value, without it any day
                // with more than 100 distinct URLs silently loses the rest.
                'filter_limit' => -1,
            ] + $goalParameters);

            foreach ($rows as $row) {
                if (!is_array($row) || !is_string($row['url'] ?? null) || '' === $row['url']) {
                    continue;
                }

                yield new UrlMetric($row['url'], $day, 'matomo.pageviews', (int) ($row['nb_hits'] ?? 0));
                yield new UrlMetric($row['url'], $day, 'matomo.visits', (int) ($row['nb_visits'] ?? 0));
                // Raw components for the derived rate tiles above - a rate
                // is not itself summable across days, so we store its
                // numerator/denominator and divide sums at read time instead.
                yield new UrlMetric($row['url'], $day, 'matomo.time_spent_seconds', (int) ($row['sum_time_spent'] ?? 0));
                yield new UrlMetric($row['url'], $day, 'matomo.entries', (int) ($row['entry_nb_visits'] ?? 0));
                yield new UrlMetric($row['url'], $day, 'matomo.entry_bounces', (int) ($row['entry_bounce_count'] ?? 0));

                yield from $this->goalMetrics->metricsForRow($goals, $row['url'], $day, $row);
            }
        }
    }
}
