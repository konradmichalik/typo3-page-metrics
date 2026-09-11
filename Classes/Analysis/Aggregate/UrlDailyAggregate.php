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

namespace KonradMichalik\Typo3PageMetrics\Analysis\Aggregate;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy\Bucket;

/**
 * UrlDailyAggregate.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class UrlDailyAggregate
{
    public function __construct(
        public DateTimeImmutable $date,
        public string $url,
        public Bucket $bucket,
        public int $status,
        public int $hits,
    ) {}
}
