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

namespace KonradMichalik\Typo3PageMetrics\Source\Matomo;

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition, UrlMetric};
use KonradMichalik\Typo3PageMetrics\Source\MetricSourceInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

use function is_array;
use function is_string;

/**
 * MatomoMetricSource.
 *
 * Calls Actions.getPageUrls with period=day once per calendar day in the
 * range, never period=range: UrlMetric->date needs one concrete day per
 * row, and per-day granularity is worth the extra API round trips since
 * this runs from a scheduled import, not a live per-request call. Uses
 * flat=1 so Matomo returns an already-flattened page table instead of a
 * tree needing manual flattening.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MatomoMetricSource implements MetricSourceInterface
{
    private const IDENTIFIER = 'matomo';

    public function __construct(
        private readonly MatomoConfigurationResolver $configurationResolver,
        private readonly MatomoApiClient $apiClient,
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
            new MetricDefinition('matomo.pageviews', 'Page views', 'actions-view', 'count', 10),
            new MetricDefinition('matomo.visits', 'Visits', 'actions-visit', 'count', 20),
        ];
    }

    public function fetch(Site $site, DateRange $range): iterable
    {
        $configuration = $this->configurationResolver->resolve($site);

        if (null === $configuration) {
            return;
        }

        foreach ($range->days() as $day) {
            $rows = $this->apiClient->request($configuration, [
                'method' => 'Actions.getPageUrls',
                'period' => 'day',
                'date' => $day->format('Y-m-d'),
                'flat' => 1,
            ]);

            foreach ($rows as $row) {
                if (!is_array($row) || !is_string($row['url'] ?? null) || '' === $row['url']) {
                    continue;
                }

                yield new UrlMetric($row['url'], $day, 'matomo.pageviews', (int) ($row['nb_hits'] ?? 0));
                yield new UrlMetric($row['url'], $day, 'matomo.visits', (int) ($row['nb_visits'] ?? 0));
            }
        }
    }
}
