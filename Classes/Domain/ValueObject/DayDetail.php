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

use DateTimeImmutable;

/**
 * DayDetail.
 *
 * Everything the day panel shows for one page on one day: the markers that
 * explain it, what each metric did that day, and the before/after block for
 * every marker that belongs to this page.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class DayDetail
{
    /**
     * @param list<Annotation>     $annotations
     * @param list<DayMetricValue> $metrics
     * @param list<DayComparison>  $comparisons
     */
    public function __construct(
        public DateTimeImmutable $date,
        public array $annotations,
        public array $metrics,
        public array $comparisons,
    ) {}
}
