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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\UnresolvedReason;

/**
 * UnresolvedUrlSummary.
 *
 * One row of the "not found pages" widget - a URL that could not be
 * matched to a TYPO3 page, summed across the widget's period.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class UnresolvedUrlSummary
{
    /**
     * Path (plus query string, if any) with the scheme and host stripped -
     * every row in the widget already belongs to one site, so repeating its
     * base URL on every line only pushes the actually distinguishing part
     * further to the right, or off narrow columns entirely.
     */
    public string $path;

    public function __construct(
        public string $url,
        public UnresolvedReason $reason,
        public int $value,
    ) {
        $path = parse_url($url, \PHP_URL_PATH);
        $query = parse_url($url, \PHP_URL_QUERY);
        $this->path = ('' !== (string) $path ? (string) $path : '/').(null !== $query && '' !== $query ? '?'.$query : '');
    }
}
