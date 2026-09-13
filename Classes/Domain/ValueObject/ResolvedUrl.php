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
 * ResolvedUrl.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ResolvedUrl
{
    /**
     * @param bool $viaRouteEnhancer the URL is not this page's own address:
     *                               TYPO3's router attributed it to the page
     *                               through the site's routing configuration,
     *                               which is how a record detail URL such as
     *                               /news/my-article lands on /news. A tile
     *                               counting such a hit is claiming "the page
     *                               and what it renders below it", not "the
     *                               page", so the distinction survives
     *                               resolution instead of being dropped here
     */
    public function __construct(
        public int $pageUid,
        public int $languageUid,
        public bool $viaRedirect,
        public bool $viaRouteEnhancer,
    ) {}
}
