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

namespace KonradMichalik\Typo3PageMetrics\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Annotation.
 *
 * A point-in-time, site-wide event (e.g. a Matomo annotation marking a
 * release) - unlike UrlMetric this has no page/URL and no summable value.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class Annotation
{
    public function __construct(
        public string $id,
        public DateTimeImmutable $date,
        public string $text,
    ) {}
}
