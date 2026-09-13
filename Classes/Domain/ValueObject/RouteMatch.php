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
 * RouteMatch.
 *
 * What the site's routing had to say about a URL that matches no page slug:
 * the page that renders it, and the record it renders there if the matched
 * route was about one.
 *
 * The record is optional because plenty of enhanced routes are not about a
 * record at all - a paginated list, a filtered overview - and those still
 * belong to the page they hang below.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RouteMatch
{
    public function __construct(
        public int $pageUid,
        public ?RecordReference $record,
    ) {}
}
