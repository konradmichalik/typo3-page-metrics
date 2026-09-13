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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\TrafficChannel;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\UrlMetric;

use function is_array;
use function is_numeric;
use function is_string;
use function str_starts_with;

/**
 * Ga4ChannelMetrics.
 *
 * Where a page's sessions came from, stored as dimensions on the sessions
 * metric rather than as metrics of their own.
 *
 * One extra report per import covers the whole range, because date is a
 * dimension of it like it is of the reports Ga4MetricProvider already sends.
 * Ga4MetricProvider pages that report and feeds the pages through here as
 * they arrive.
 *
 * Scoped to pagePath and not to landingPage, although the session-scoped
 * channel dimension would combine with either. The split is read back as the
 * parts of the sessions tile, and that tile counts sessions that included
 * the page, so a landing-page-scoped split would list parts that do not add
 * up to the whole they are shown under. It is also what keeps the two
 * providers comparable: Matomo's page segment is likewise about visits that
 * touched the page, not about visits that started on it.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class Ga4ChannelMetrics
{
    private const METRIC = 'ga4.sessions';

    /**
     * GA4's sessionDefaultChannelGroup values, folded onto the vocabulary
     * shared with Matomo (see MatomoChannelMetrics).
     *
     * GA4 splits paid from organic and Matomo, out of the box, does not: a
     * tagged ad click is a campaign to Matomo whatever it was bought on.
     * Every paid group therefore folds into campaign, which is the reading
     * an editor wants anyway ("this came from something we ran"), and the
     * one place the two can genuinely disagree is an untagged paid search
     * click, which Matomo types as plain search. That is a property of
     * Matomo's referrer detection, not something this map can repair, and it
     * is written down in docs/providers/matomo.md rather than papered over.
     *
     * Organic video and shopping fold into referral because that is what
     * Matomo makes of a youtube.com or a shop referrer: a website.
     *
     * @var array<string, TrafficChannel>
     */
    private const CHANNELS = [
        'Direct' => TrafficChannel::Direct,
        'Organic Search' => TrafficChannel::Search,
        'Organic Social' => TrafficChannel::Social,
        'Referral' => TrafficChannel::Referral,
        'Organic Video' => TrafficChannel::Referral,
        'Organic Shopping' => TrafficChannel::Referral,
        'Paid Search' => TrafficChannel::Campaign,
        'Paid Social' => TrafficChannel::Campaign,
        'Paid Video' => TrafficChannel::Campaign,
        'Paid Shopping' => TrafficChannel::Campaign,
        'Display' => TrafficChannel::Campaign,
        'Email' => TrafficChannel::Campaign,
        'Affiliates' => TrafficChannel::Campaign,
        'Audio' => TrafficChannel::Campaign,
        'SMS' => TrafficChannel::Campaign,
        'Mobile Push Notifications' => TrafficChannel::Campaign,
        'Cross-network' => TrafficChannel::Campaign,
        'Unassigned' => TrafficChannel::Other,
    ];

    /**
     * @param array{startDate: string, endDate: string} $dateRange
     *
     * @return array<string, mixed>
     */
    public function reportBody(array $dateRange): array
    {
        return [
            'dateRanges' => [$dateRange],
            'dimensions' => [['name' => 'date'], ['name' => 'pagePath'], ['name' => 'sessionDefaultChannelGroup']],
            'metrics' => [['name' => 'sessions']],
        ];
    }

    /**
     * Rows are taken one at a time and never collected: this report is the
     * largest of the import, one row per channel per page per day against
     * the page report's one row per page per day, and holding it whole is
     * what made a first import of a large site die on memory.
     *
     * @param iterable<array<string, mixed>> $rows
     *
     * @return iterable<UrlMetric>
     */
    public function metricsFor(string $baseUrl, iterable $rows): iterable
    {
        foreach ($rows as $row) {
            $parsed = $this->parseRow($row);

            if (null === $parsed) {
                continue;
            }

            [$day, $path, $channel, $sessions] = $parsed;

            yield new UrlMetric($baseUrl.$path, $day, self::METRIC, $sessions, $channel->dimension());
        }
    }

    /**
     * Null for anything unusable, which here includes a session count of
     * zero: GA4 does not report a page/channel pair that never happened, and
     * a row saying nothing happened says what its absence already says.
     *
     * @param array<string, mixed> $row
     *
     * @return array{0: DateTimeImmutable, 1: string, 2: TrafficChannel, 3: int}|null
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
        $groupValue = $dimensionValues[2]['value'] ?? null;

        if (!is_string($dateValue) || !is_string($pathValue) || !is_string($groupValue) || !str_starts_with($pathValue, '/')) {
            return null;
        }

        $day = DateTimeImmutable::createFromFormat('Ymd', $dateValue);
        $rawValue = $metricValues[0]['value'] ?? null;
        $sessions = is_numeric($rawValue) ? (int) round((float) $rawValue) : 0;

        if (false === $day || $sessions <= 0) {
            return null;
        }

        // Google adds channel groups over time, so an unknown one is
        // counted as unassigned rather than dropped: a split that silently
        // loses traffic is worse than one that admits it cannot name it.
        return [$day->setTime(0, 0), $pathValue, self::CHANNELS[$groupValue] ?? TrafficChannel::Other, $sessions];
    }
}
