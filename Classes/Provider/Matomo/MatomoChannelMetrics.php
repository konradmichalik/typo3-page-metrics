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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\TrafficChannel;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\UrlMetric;

use function http_build_query;
use function is_array;
use function is_string;
use function sprintf;

/**
 * MatomoChannelMetrics.
 *
 * Where a page's visits came from, stored as dimensions on the visits metric
 * rather than as metrics of their own.
 *
 * The segment runs the opposite way round from the obvious reading of the
 * Matomo API. Asking Referrers.getReferrerType with segment=pageUrl==<url>
 * answers the question directly, but costs one request per page per day, so
 * a site with 800 pages would pay 800 requests for a number it shows in a
 * popover. Asking Actions.getPageUrls with segment=referrerType==<type>
 * returns the identical figures transposed - every page's visits for one
 * channel instead of every channel's visits for one page - at a cost of one
 * request per channel, which is a constant.
 *
 * That constant is then folded back into a single HTTP call per day through
 * API.getBulkRequest, so the split costs one extra request per day per site,
 * the same order as the report it hangs off. Bulk also degrades the way this
 * feature has to: a Matomo without the Referrers plugin fails its
 * sub-request in its own slot rather than failing the request the page views
 * arrive in.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MatomoChannelMetrics
{
    /**
     * The visits tile, because that is the number the split adds up to:
     * Matomo types a referrer per visit, not per page view.
     */
    private const METRIC = 'matomo.visits';

    /**
     * Matomo's referrerType segment values, mapped onto the vocabulary
     * shared with GA4 (see Ga4ChannelMetrics). Only two names differ:
     * Matomo's "website" is what GA4 calls a referral, and Matomo has no
     * equivalent of GA4's "Unassigned" because it types every visit.
     *
     * The order is the order the popover lists them in, and it is this map
     * that fixes it: the slot a sub-request answers in is its position here.
     *
     * @var array<string, TrafficChannel>
     */
    private const CHANNELS = [
        'direct' => TrafficChannel::Direct,
        'search' => TrafficChannel::Search,
        'social' => TrafficChannel::Social,
        'website' => TrafficChannel::Referral,
        'campaign' => TrafficChannel::Campaign,
    ];

    /**
     * One bulk request carrying one segmented page report per channel.
     *
     * @return array<string, int|string>
     */
    public function bulkRequestParameters(MatomoConfiguration $configuration, DateTimeImmutable $day): array
    {
        $parameters = ['method' => 'API.getBulkRequest'];
        $slot = 0;

        foreach (self::CHANNELS as $referrerType => $channel) {
            // Left as a plain query string on purpose: the HTTP layer
            // percent-encodes the parameter value once more on its way out,
            // which is exactly the double encoding Matomo unwraps to get the
            // sub-request back.
            $parameters[sprintf('urls[%d]', $slot)] = http_build_query([
                'method' => 'Actions.getPageUrls',
                'idSite' => $configuration->idSite,
                'period' => 'day',
                'date' => $day->format('Y-m-d'),
                'flat' => 1,
                'filter_limit' => -1,
                'segment' => sprintf('referrerType==%s', $referrerType),
            ]);
            ++$slot;
        }

        return $parameters;
    }

    /**
     * The bulk response is a list of sub-responses in the order they were
     * asked for, so the slot is what identifies the channel. A slot that
     * carries an error object instead of a page table (a deactivated
     * Referrers plugin, a segment the token may not use) contributes
     * nothing, which leaves the tile exactly as it was before this existed.
     *
     * @param array<mixed> $response
     *
     * @return iterable<UrlMetric>
     */
    public function metricsFor(array $response, DateTimeImmutable $day): iterable
    {
        $slot = 0;

        foreach (self::CHANNELS as $channel) {
            $rows = $response[$slot] ?? null;
            ++$slot;

            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (!is_array($row) || !is_string($row['url'] ?? null) || '' === $row['url']) {
                    continue;
                }

                $visits = (int) ($row['nb_visits'] ?? 0);

                // Zero says what the absent row already says, and writing it
                // would multiply the fact table by the number of channels
                // for every page that saw none of them.
                if ($visits > 0) {
                    yield new UrlMetric($row['url'], $day, self::METRIC, $visits, $channel->dimension());
                }
            }
        }
    }
}
