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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\BenchmarkBand;

/**
 * TileBenchmark.
 *
 * What a tile says about the rest of its site: the typical page's value
 * formatted the same way the tile's own is, how many pages that was taken
 * over, and roughly where this page sits among them.
 *
 * One object rather than three fields on MetricTile, because the three are
 * one statement. Either there is a comparison to show or there is not, and
 * three nullable-ish fields make "half a comparison" expressible when it is
 * not a thing that exists. SiteBenchmarkResult is the raw answer; this is
 * that answer once the tile's unit has been applied to it.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class TileBenchmark
{
    /**
     * @param string             $formattedMedian what the typical page of this site did over the same period, formatted
     *                                            the same way as the tile's own value
     * @param BenchmarkBand|null $band            roughly where this page sits in that field, null for a rate metric and
     *                                            on a site too small to divide (see SiteBenchmark)
     * @param int                $pageCount       how many pages the median was taken over, so the tooltip can say how
     *                                            wide the field is instead of asking the reader to trust a bare number
     */
    public function __construct(
        public string $formattedMedian,
        public ?BenchmarkBand $band,
        public int $pageCount,
    ) {}
}
