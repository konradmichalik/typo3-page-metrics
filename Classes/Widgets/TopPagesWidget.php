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

namespace KonradMichalik\Typo3PageMetrics\Widgets;

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\SiteMetricsOverview;

/**
 * TopPagesWidget.
 *
 * The highest-traffic pages per site, resolved to their current titles and
 * linked into the page module - unlike a raw Matomo/GA4 URL list, this is
 * only possible because the daily import already resolves URLs to page
 * UIDs.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class TopPagesWidget extends AbstractSiteMetricsWidget
{
    protected function templateName(): string
    {
        return 'Widget/TopPages';
    }

    protected function periodDays(): int
    {
        return 30;
    }

    /**
     * @param list<SiteMetricsOverview> $overviews
     *
     * @return array<string, mixed>
     */
    protected function buildViewVariables(array $overviews): array
    {
        return ['sites' => $overviews];
    }
}
