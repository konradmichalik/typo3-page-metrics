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

namespace KonradMichalik\Typo3PageMetrics\Provider\Ga4;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Configuration\{GoalConfiguration, SiteConfigurationReader};
use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricUnit;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{MetricDefinition, UrlMetric};
use TYPO3\CMS\Core\Site\Entity\Site;

use function array_map;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * Ga4KeyEventMetrics.
 *
 * The goals a site declared for GA4, which GA4 calls key events: their
 * metric definitions, the report that reads them, and the values parsed back
 * out of it.
 *
 * Unlike Matomo's goal columns, a key event cannot ride along in the page
 * report the importer already runs. Splitting it by name needs the eventName
 * dimension, and adding that dimension to the existing report would split
 * every page-view row by event name as well, which is a different (and
 * wrong) number for every metric already in it. So this is a report of its
 * own, run once per import and only for a site that configured a goal: a
 * site without goals sends exactly the requests it always sent.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class Ga4KeyEventMetrics
{
    private const PROVIDER = 'ga4';

    /**
     * Behind the fixed metrics (10 to 40), the same reason as
     * MatomoGoalMetrics.
     */
    private const SORT_ORDER_BASE = 100;
    private const SORT_ORDER_STEP = 10;

    private const COLOR = '#1a7f37';

    public function __construct(
        private SiteConfigurationReader $siteConfigurationReader,
    ) {}

    /**
     * @return list<MetricDefinition>
     */
    public function definitionsFor(Site $site): array
    {
        $definitions = [];

        foreach ($this->goalsFor($site) as $index => $goal) {
            $definitions[] = new MetricDefinition(
                $this->identifier($goal->id),
                $goal->label,
                sprintf('Occurrences of the GA4 key event "%s" on this page in the selected period.', $goal->id),
                'tabler-target',
                MetricUnit::Count,
                self::SORT_ORDER_BASE + $index * self::SORT_ORDER_STEP,
                self::COLOR,
                $goal->polarity,
            );
        }

        return $definitions;
    }

    /**
     * The runReport body for this site's key events, or null when it has no
     * goals and no second report is to be sent at all. Filtered server-side
     * to the configured names so GA4 does not page through every event the
     * property records.
     *
     * @param array{startDate: string, endDate: string} $dateRange
     *
     * @return array<string, mixed>|null
     */
    public function reportBody(Site $site, array $dateRange): ?array
    {
        $goals = $this->goalsFor($site);

        if ([] === $goals) {
            return null;
        }

        return [
            'dateRanges' => [$dateRange],
            'dimensions' => [['name' => 'date'], ['name' => 'pagePath'], ['name' => 'eventName']],
            'metrics' => [['name' => 'keyEvents']],
            'dimensionFilter' => [
                'filter' => [
                    'fieldName' => 'eventName',
                    'inListFilter' => ['values' => $this->eventNames($goals)],
                ],
            ],
        ];
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return iterable<UrlMetric>
     */
    public function metricsFor(Site $site, string $baseUrl, iterable $rows): iterable
    {
        $configuredEvents = $this->eventNames($this->goalsFor($site));

        foreach ($rows as $row) {
            $parsed = $this->parseRow($row);

            if (null === $parsed) {
                continue;
            }

            [$day, $path, $eventName, $value] = $parsed;

            // GA4's dimensionFilter is applied by the API, so an event this
            // site never asked about should not arrive. Checked anyway: an
            // unconfigured name has no definition, so its rows would be
            // stored under an identifier nothing ever reads.
            if (in_array($eventName, $configuredEvents, true)) {
                yield new UrlMetric($baseUrl.$path, $day, $this->identifier($eventName), $value);
            }
        }
    }

    /**
     * @param list<GoalConfiguration> $goals
     *
     * @return list<string>
     */
    private function eventNames(array $goals): array
    {
        return array_map(static fn (GoalConfiguration $goal): string => $goal->id, $goals);
    }

    /**
     * @return list<GoalConfiguration>
     */
    private function goalsFor(Site $site): array
    {
        return $this->siteConfigurationReader->read($site)->goalsFor(self::PROVIDER);
    }

    /**
     * Null for anything unusable, which for this report includes a count of
     * zero: GA4 does not report a page/event pair that never happened, and a
     * row that says nothing happened says what its absence already says.
     *
     * @param array<string, mixed> $row
     *
     * @return array{0: DateTimeImmutable, 1: string, 2: string, 3: int}|null
     */
    private function parseRow(array $row): ?array
    {
        $dimensionValues = $row['dimensionValues'] ?? [];
        $metricValues = $row['metricValues'] ?? [];

        if (!is_array($dimensionValues) || !is_array($metricValues)) {
            return null;
        }

        $dateValue = $dimensionValues[0]['value'] ?? null;
        $pathValue = $dimensionValues[1]['value'] ?? null;
        $eventValue = $dimensionValues[2]['value'] ?? null;

        if (!is_string($dateValue) || !is_string($pathValue) || !is_string($eventValue) || !str_starts_with($pathValue, '/')) {
            return null;
        }

        $day = DateTimeImmutable::createFromFormat('Ymd', $dateValue);
        $rawValue = $metricValues[0]['value'] ?? null;
        $value = is_numeric($rawValue) ? (int) round((float) $rawValue) : 0;

        if (false === $day || $value <= 0) {
            return null;
        }

        return [$day->setTime(0, 0), $pathValue, $eventValue, $value];
    }

    private function identifier(string $eventName): string
    {
        return sprintf('%s.key_event.%s', self::PROVIDER, $eventName);
    }
}
