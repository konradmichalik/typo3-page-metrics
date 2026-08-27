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
 * AnnotationsWidget.
 *
 * Recent site-wide annotations (e.g. Matomo release markers) per site - the
 * same events the chart widget draws as dashed markers, listed with their
 * text. A longer period than the other widgets since annotations are sparse
 * events, not daily volume.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class AnnotationsWidget extends AbstractSiteMetricsWidget
{
    protected function templateName(): string
    {
        return 'Widget/Annotations';
    }

    protected function periodDays(): int
    {
        return 90;
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
