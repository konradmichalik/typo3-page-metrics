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

namespace KonradMichalik\Typo3PageMetrics\Resolver;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\UnresolvedReason;

/**
 * Last-resort classifier: called only once SlugLookup and RedirectFollower
 * have both failed to find a page for the path, to give the unresolved
 * report a more actionable reason than a blanket "unknown".
 */
final class PathClassifier
{
    /** @var list<string> */
    private const array FILE_PREFIXES = ['/fileadmin/', '/uploads/', '/_assets/'];

    /** @var list<string> */
    private const array NON_PAGE_PREFIXES = ['/api/', '/typo3/', '/typo3temp/'];

    public function classify(string $path): UnresolvedReason
    {
        foreach (self::FILE_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return UnresolvedReason::File;
            }
        }

        foreach (self::NON_PAGE_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return UnresolvedReason::NonPage;
            }
        }

        return UnresolvedReason::Unknown;
    }
}
