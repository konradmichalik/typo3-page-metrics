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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\TrendDirection;

/**
 * TopPage.
 *
 * One row of the "most viewed pages" or "trend" widget - a page UID
 * resolved to its current title, with its value and trend against the
 * previous period of the same length.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class TopPage
{
    /**
     * Fluid's `!== null` in an f:if condition does not actually compare
     * against PHP null (TYPO3Fluid\Fluid\Core\Parser\BooleanParser has no
     * concept of a null literal - a bare `null` token falls through to
     * being treated as the *string* "null", so the comparison is never
     * false) - this precomputed flag is what templates check instead.
     */
    public bool $isNew;

    public function __construct(
        public int $pageUid,
        public string $title,
        public string $formattedValue,
        public ?int $deltaPercent,
        public TrendDirection $trend,
    ) {
        $this->isNew = null === $deltaPercent;
    }
}
