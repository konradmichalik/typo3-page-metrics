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

namespace KonradMichalik\Typo3PageMetrics\Provider\Typo3;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\AnnotationCategory;

/**
 * DailyPageChange.
 *
 * One page, one day, one kind of activity, already folded: $changes says how
 * often that activity happened, so five edits to one page on one day arrive
 * here as a single entry saying five rather than as five entries.
 *
 * Lives beside the provider rather than in Domain/ValueObject because it
 * never leaves it: PageChangeLookup produces it and Typo3AnnotationProvider
 * turns it into an Annotation in the same breath.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class DailyPageChange
{
    public function __construct(
        public int $pageUid,
        public DateTimeImmutable $date,
        public int $changes,
        public AnnotationCategory $category,
    ) {}
}
