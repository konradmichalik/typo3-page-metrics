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
 * NotFoundPagesWidget.
 *
 * URLs the daily import could not resolve to a TYPO3 page, broken down by
 * UnresolvedReason (foreign host, file, non-page, unknown) - unlike a plain
 * 404 list, the reason tells the editor whether it is a real broken link, a
 * download, or just tracking noise.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class NotFoundPagesWidget extends AbstractSiteMetricsWidget
{
    protected function templateName(): string
    {
        return 'Widget/NotFoundPages';
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
