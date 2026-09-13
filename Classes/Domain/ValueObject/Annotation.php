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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\AnnotationCategory;

/**
 * Annotation.
 *
 * An event worth explaining a change in the numbers by - a release, a
 * campaign, a rewrite. Unlike UrlMetric it has no summable value.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class Annotation
{
    /**
     * True for a marker that spans days rather than falling on one. Fluid's
     * boolean parser has no concept of a null literal, so a template cannot
     * ask whether $dateUntil is null - the same reason TopPage::$isNew and
     * MetricTile::$isNew exist.
     */
    public bool $isRange;

    /**
     * @param AnnotationCategory     $category        what kind of event this was, which is what lets a chart
     *                                                say so without being hovered
     * @param DateTimeImmutable|null $dateUntil       the last day this event covers, null for one that fell on a
     *                                                single day. A campaign runs for weeks, and drawing it as a
     *                                                tick on its first day says it was over by the evening
     * @param int                    $pageUid         the page this explains, or 0 for the whole site. Site-wide
     *                                                was the only option before, and stays the default: a marker
     *                                                that belongs to one page is noise on every other one
     * @param bool                   $includeSubpages whether a page-scoped marker also explains everything
     *                                                below it, for the section relaunch that touched a subtree
     * @param int                    $collapsedCount  how many pages this one marker stands for on the
     *                                                site-wide surfaces, 0 for a marker that stands only for
     *                                                itself (see AnnotationRepository::forSiteAndRange()). A
     *                                                count here rather than a separate collapsed-marker class:
     *                                                a summary is still a marker on a day with a kind and a
     *                                                text, and a second type would force every consumer - the
     *                                                chart payload, the widget templates, the page tiles - to
     *                                                handle two shapes to render the same tick
     */
    public function __construct(
        public string $id,
        public DateTimeImmutable $date,
        public string $text,
        public AnnotationCategory $category = AnnotationCategory::Release,
        public ?DateTimeImmutable $dateUntil = null,
        public int $pageUid = 0,
        public bool $includeSubpages = false,
        public int $collapsedCount = 0,
    ) {
        $this->isRange = null !== $dateUntil && $dateUntil > $date;
    }

    /**
     * Fluid resolves properties and getters, and AnnotationCategory's icon()
     * and label() are neither, so a template cannot reach them through
     * {annotation.category}. These two exist for the templates.
     */
    public function getCategoryIcon(): string
    {
        return $this->category->icon();
    }

    public function getCategoryLabel(): string
    {
        return $this->category->label();
    }
}
