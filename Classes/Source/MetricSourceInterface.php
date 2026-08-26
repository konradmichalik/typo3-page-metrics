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

namespace KonradMichalik\Typo3PageMetrics\Source;

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition, RateMetricDefinition, UrlMetric};
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * MetricSourceInterface.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
interface MetricSourceInterface
{
    public function getIdentifier(): string;

    public function supports(Site $site): bool;

    /**
     * @return list<MetricDefinition>
     */
    public function getMetricDefinitions(): array;

    /**
     * @return list<RateMetricDefinition>
     */
    public function getRateMetricDefinitions(): array;

    /**
     * Optional deep link into this source's own external UI (e.g. Matomo),
     * pre-filtered to $pageUrl and $range. Null when the source has no such
     * external UI, or is not configured for $site.
     */
    public function getExternalLink(Site $site, string $pageUrl, DateRange $range): ?string;

    /**
     * @return iterable<UrlMetric>
     */
    public function fetch(Site $site, DateRange $range): iterable;
}
