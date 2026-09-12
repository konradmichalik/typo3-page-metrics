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

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Configuration\{GoalConfiguration, SiteConfigurationReader};
use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricUnit;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{MetricDefinition, UrlMetric};
use TYPO3\CMS\Core\Site\Entity\Site;

use function is_numeric;
use function sprintf;

/**
 * MatomoGoalMetrics.
 *
 * Everything about the goals a site declared for Matomo: the metric
 * definitions they add to the tile row, the two request parameters that make
 * Actions.getPageUrls carry them, and the values read back out of its rows.
 *
 * Its own class rather than more methods on MatomoMetricProvider: the
 * provider describes a fixed set of metrics every Matomo site has, and this
 * describes a set that only exists because an operator configured it.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MatomoGoalMetrics
{
    private const PROVIDER = 'matomo';

    /**
     * Behind the fixed metrics (10 to 40), so a goal tile never displaces
     * the page views an editor opens the module for.
     */
    private const SORT_ORDER_BASE = 100;
    private const SORT_ORDER_STEP = 10;

    private const CONVERSIONS_COLOR = '#1a7f37';
    private const REVENUE_COLOR = '#bc4c00';

    public function __construct(
        private SiteConfigurationReader $siteConfigurationReader,
    ) {}

    /**
     * @return list<GoalConfiguration>
     */
    public function goalsFor(Site $site): array
    {
        return $this->siteConfigurationReader->read($site)->goalsFor(self::PROVIDER);
    }

    /**
     * @return list<MetricDefinition>
     */
    public function definitionsFor(Site $site): array
    {
        $definitions = [];

        foreach ($this->goalsFor($site) as $index => $goal) {
            $sortOrder = self::SORT_ORDER_BASE + $index * self::SORT_ORDER_STEP;

            $definitions[] = new MetricDefinition(
                $this->conversionsIdentifier($goal->id),
                $goal->label,
                sprintf('Conversions of the Matomo goal "%s" attributed to this page in the selected period.', $goal->label),
                'tabler-target',
                MetricUnit::Count,
                $sortOrder,
                self::CONVERSIONS_COLOR,
                $goal->polarity,
            );

            if ($goal->trackRevenue) {
                $definitions[] = new MetricDefinition(
                    $this->revenueIdentifier($goal->id),
                    sprintf('%s revenue', $goal->label),
                    sprintf('Revenue Matomo attributes to conversions of the goal "%s" on this page, in the currency of the Matomo site.', $goal->label),
                    'tabler-coin',
                    MetricUnit::Currency,
                    $sortOrder + 5,
                    self::REVENUE_COLOR,
                    $goal->polarity,
                );
            }
        }

        return $definitions;
    }

    /**
     * The two parameters that make Matomo append goal_<id>_nb_conversions and
     * goal_<id>_revenue columns to the page table the importer already asks
     * for, so conversions cost no extra request. Empty for a site with no
     * goals, which then sends exactly the request it always sent.
     *
     * @param list<GoalConfiguration> $goals
     *
     * @return array<string, int>
     */
    public function requestParameters(array $goals): array
    {
        if ([] === $goals) {
            return [];
        }

        // idGoal=0 is Matomo's "all goals", which is cheaper than one request
        // per configured goal and returns the columns for each of them.
        return ['idGoal' => 0, 'filter_update_columns_when_show_all_goals' => 1];
    }

    /**
     * @param list<GoalConfiguration> $goals
     * @param array<mixed>            $row
     *
     * @return iterable<UrlMetric>
     */
    public function metricsForRow(array $goals, string $url, DateTimeImmutable $day, array $row): iterable
    {
        foreach ($goals as $goal) {
            $conversions = $this->readValue($row, sprintf('goal_%s_nb_conversions', $goal->id), 1);

            if (null !== $conversions) {
                yield new UrlMetric($url, $day, $this->conversionsIdentifier($goal->id), $conversions);
            }

            if (!$goal->trackRevenue) {
                continue;
            }

            // Money is stored in minor units, see MetricUnit::Currency.
            $revenue = $this->readValue($row, sprintf('goal_%s_revenue', $goal->id), 100);

            if (null !== $revenue) {
                yield new UrlMetric($url, $day, $this->revenueIdentifier($goal->id), $revenue);
            }
        }
    }

    /**
     * Null rather than zero for anything not usable: Matomo omits the goal
     * columns entirely for a page that converted nothing, and most pages
     * convert nothing, so writing those rows would multiply the fact table
     * by the number of configured goals to say what its absence already says.
     * A column that is present but not a number is a malformed response, and
     * is dropped the same way rather than counted as a conversion.
     *
     * @param array<mixed> $row
     */
    private function readValue(array $row, string $column, int $factor): ?int
    {
        $raw = $row[$column] ?? null;

        if (!is_numeric($raw)) {
            return null;
        }

        $value = (int) round((float) $raw * $factor);

        return $value > 0 ? $value : null;
    }

    private function conversionsIdentifier(string $goalId): string
    {
        return sprintf('%s.goal.%s.conversions', self::PROVIDER, $goalId);
    }

    private function revenueIdentifier(string $goalId): string
    {
        return sprintf('%s.goal.%s.revenue', self::PROVIDER, $goalId);
    }
}
