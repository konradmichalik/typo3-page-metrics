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

namespace KonradMichalik\Typo3PageMetrics\Domain\Enum;

/**
 * MetricGroup.
 *
 * Who the metric counts. An editor opening Web > Page is asking how the page
 * performs with people; how often a crawler fetched it is a different
 * question with a different answer, and putting the two in one row of
 * equally weighted tiles asserts an equivalence that does not hold.
 *
 * Machine metrics are not hidden by that distinction, they are summarised:
 * the audience ones keep the row and every Machine metric of a provider is
 * folded into a single tile that breaks itself down when opened.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
enum MetricGroup: string
{
    case Audience = 'audience';
    case Machine = 'machine';
}
