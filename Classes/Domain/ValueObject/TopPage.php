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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricPolarity, TrendDirection, TrendSentiment};

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

    /**
     * Why the row has no percentage, where the reason is not the page: the
     * period ranked against lies outside the imported window, so its sum is
     * built out of days that were never fetched (see
     * ImportCoverage::covers()). Same precomputed shape as $isNew and for
     * the same reason, and the counterpart of MetricTile::$hasNoComparison -
     * the widget tables render no badge where it is true, because "new" is
     * a claim about the page and this is a claim about the import.
     */
    public bool $hasNoComparison;

    /**
     * Same reasoning as MetricTile::$sentiment: the row's colour has to say
     * "better / worse", which direction alone cannot for a metric where a
     * rise is the bad outcome.
     */
    public TrendSentiment $sentiment;

    public function __construct(
        public int $pageUid,
        public string $title,
        public string $formattedValue,
        public ?int $deltaPercent,
        public TrendDirection $trend,
        public MetricPolarity $polarity,
        bool $previousPeriodCovered,
    ) {
        // And not covered: a row the import cannot speak for is not a new
        // page, it is a page nobody asked about. The templates branch on
        // $hasNoComparison first, so this only decides what a row that does
        // have a comparison says, but leaving the two collapsed here would
        // hand the next reader of this object the same trap MetricTile just
        // got out of.
        $this->isNew = null === $deltaPercent && $previousPeriodCovered;
        $this->hasNoComparison = !$previousPeriodCovered;
        $this->sentiment = TrendSentiment::of($trend, $polarity);
    }
}
