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
 * DayComparison.
 *
 * The before/after block belonging to one page-scoped marker: which marker
 * it answers for, the two windows it measured, and a row per metric.
 *
 * Only a page-scoped marker gets one. A site-wide release marker says
 * nothing about this page in particular, and putting a per-page figure under
 * it would turn "the site deployed" into "this page improved because of it".
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class DayComparison
{
    /**
     * @param list<BeforeAfterComparison> $rows
     */
    public function __construct(
        public string $markerText,
        public string $markerCategoryIcon,
        public int $windowDays,
        public string $beforeLabel,
        public string $afterLabel,
        public array $rows,
    ) {}
}
