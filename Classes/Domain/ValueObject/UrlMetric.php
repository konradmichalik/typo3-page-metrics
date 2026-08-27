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
 * UrlMetric.
 *
 * One (date, url, metric, value) fact yielded by a MetricSourceInterface,
 * before resolution. This is the deliberately narrow common denominator
 * every source must fit into.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class UrlMetric
{
    public function __construct(
        public string $url,
        public DateTimeImmutable $date,
        public string $metric,
        public int $value,
        public ?string $dimension = null,
    ) {}
}
