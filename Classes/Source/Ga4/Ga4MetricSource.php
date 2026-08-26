<?php

declare(strict_types=1);

/*
 * This file is part of the "page_metrics" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3PageMetrics\Source\Ga4;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricUnit;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition, RateMetricDefinition, UrlMetric};
use KonradMichalik\Typo3PageMetrics\Source\MetricSourceInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

use function is_array;
use function is_string;
use function str_starts_with;

/**
 * Ga4MetricSource.
 *
 * Calls the GA4 Data API's runReport once for the whole requested range
 * (never once per day like MatomoMetricSource): GA4 charges quota per API
 * call, so a per-day loop would be wasteful/rate-limit-risky over a large
 * retention window. Two reports are needed because bounce rate is scoped
 * to the landing page (session start), a different dimension than the
 * page-views/sessions report's pagePath.
 *
 * "Avg. time on page" and "bounce rate" are GA4 approximations, not the
 * same definitions Matomo uses - see the descriptions on their
 * RateMetricDefinitions.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class Ga4MetricSource implements MetricSourceInterface
{
    private const IDENTIFIER = 'ga4';

    public function __construct(
        private readonly Ga4ConfigurationResolver $configurationResolver,
        private readonly Ga4ApiClient $apiClient,
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
            new MetricDefinition('ga4.pageviews', 'Page views', 'GA4 screenPageViews for this page in the selected period.', 'tabler-eye-search', MetricUnit::Count, 10, '#f9ab00'),
            new MetricDefinition('ga4.sessions', 'Sessions', 'GA4 sessions that included this page in the selected period.', 'tabler-chart-dots', MetricUnit::Count, 20, '#e37400'),
        ];
    }

    public function getRateMetricDefinitions(): array
    {
        return [
            new RateMetricDefinition(
                'ga4.avg_time_on_page',
                'Avg. time on page',
                'Approximation from GA4: total user engagement time on this page divided by sessions. Not the same definition as Matomo\'s avg. time on page.',
                'tabler-clock',
                MetricUnit::Seconds,
                30,
                '#f9ab00',
                'ga4.engagement_time_seconds',
                'ga4.sessions',
            ),
            new RateMetricDefinition(
                'ga4.bounce_rate',
                'Bounce rate',
                'Approximation from GA4: engagement-based bounce rate for sessions that landed on this page. Not the same definition as Matomo\'s bounce rate.',
                'tabler-door-exit',
                MetricUnit::Percent,
                40,
                '#e37400',
                'ga4.landing_bounces',
                'ga4.landing_sessions',
            ),
        ];
    }

    public function getExternalLink(Site $site, string $pageUrl, DateRange $range): ?string
    {
        // GA4's Analytics UI has no stable, documented URL scheme for a
        // pre-filtered single-page report the way Matomo does - a broken
        // or unstable link would be worse than none.
        return null;
    }

    public function fetch(Site $site, DateRange $range): iterable
    {
        $configuration = $this->configurationResolver->resolve($site);

        if (null === $configuration) {
            return;
        }

        $base = $site->getBase();
        $baseUrl = $base->getScheme().'://'.$base->getAuthority();
        $dateRange = ['startDate' => $range->from->format('Y-m-d'), 'endDate' => $range->until->format('Y-m-d')];

        $pageReport = $this->apiClient->runReport($configuration, [
            'dateRanges' => [$dateRange],
            'dimensions' => [['name' => 'date'], ['name' => 'pagePath']],
            'metrics' => [['name' => 'screenPageViews'], ['name' => 'sessions'], ['name' => 'userEngagementDuration']],
            'limit' => 100000,
        ]);

        foreach ($this->rows($pageReport) as [$day, $path, $values]) {
            $url = $baseUrl.$path;
            yield new UrlMetric($url, $day, 'ga4.pageviews', (int) round((float) $values[0]));
            yield new UrlMetric($url, $day, 'ga4.sessions', (int) round((float) $values[1]));
            yield new UrlMetric($url, $day, 'ga4.engagement_time_seconds', (int) round((float) $values[2]));
        }

        $landingReport = $this->apiClient->runReport($configuration, [
            'dateRanges' => [$dateRange],
            'dimensions' => [['name' => 'date'], ['name' => 'landingPage']],
            'metrics' => [['name' => 'sessions'], ['name' => 'bounceRate']],
            'limit' => 100000,
        ]);

        foreach ($this->rows($landingReport) as [$day, $path, $values]) {
            $url = $baseUrl.$path;
            $sessions = (int) round((float) $values[0]);
            $bounceRate = (float) $values[1];
            yield new UrlMetric($url, $day, 'ga4.landing_sessions', $sessions);
            // GA4's Data API returns bounceRate as a pre-computed ratio, not
            // a raw count - derive a plain summable integer from it here so
            // it behaves like every other raw rate component.
            yield new UrlMetric($url, $day, 'ga4.landing_bounces', (int) round($sessions * $bounceRate));
        }
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return iterable<array{0: DateTimeImmutable, 1: string, 2: list<string>}>
     */
    private function rows(array $report): iterable
    {
        $rows = $report['rows'] ?? [];

        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $parsedRow = is_array($row) ? $this->parseRow($row) : null;

            if (null !== $parsedRow) {
                yield $parsedRow;
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{0: DateTimeImmutable, 1: string, 2: list<string>}|null
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

        if (!is_string($dateValue) || !is_string($pathValue) || '' === $pathValue || !str_starts_with($pathValue, '/')) {
            return null;
        }

        $day = DateTimeImmutable::createFromFormat('Ymd', $dateValue);

        if (false === $day) {
            return null;
        }

        return [$day->setTime(0, 0), $pathValue, $this->extractMetricValues($metricValues)];
    }

    /**
     * @param array<mixed> $metricValues
     *
     * @return list<string>
     */
    private function extractMetricValues(array $metricValues): array
    {
        $values = [];

        foreach ($metricValues as $metricValue) {
            $values[] = is_array($metricValue) && is_string($metricValue['value'] ?? null) ? $metricValue['value'] : '0';
        }

        return $values;
    }
}
