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

namespace KonradMichalik\Typo3PageMetrics\Domain\ValueObject;

/**
 * RateTotals.
 *
 * One rate metric over one period: the two sums it was derived from and the
 * ratio they produce.
 *
 * The sums travel with the ratio because they answer a question the ratio
 * cannot. A bounce rate of zero is a real, meaningful value; a bounce rate
 * with no visits underneath it is a metric this page never recorded, and
 * both spell 0. Every surface that has to tell "no data" from "zero" reads
 * the denominator to do it: the per-page trend ranking skips a page that had
 * neither, and the pages table leaves its cell empty rather than printing a
 * flattering zero.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RateTotals
{
    public function __construct(
        public int $numerator,
        public int $denominator,
        public int $ratio,
    ) {}
}
