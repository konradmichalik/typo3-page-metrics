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
 * SiteBenchmarkResult.
 *
 * What the rest of the site did with the same metric over the same period,
 * so a tile can answer "is that good?" instead of only "is that more than
 * last time?".
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class SiteBenchmarkResult
{
    /**
     * @param int                $median    the typical page's value, in the metric's own unit
     * @param int                $pageCount how many pages carried data, which is what decides whether
     *                                      a band is reported at all
     * @param BenchmarkBand|null $band      null on a site with too few pages for the question to mean
     *                                      anything, and always null for a rate metric (see SiteBenchmark)
     */
    public function __construct(
        public int $median,
        public int $pageCount,
        public ?BenchmarkBand $band,
    ) {}
}
