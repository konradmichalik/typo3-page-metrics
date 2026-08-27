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

namespace KonradMichalik\Typo3PageMetrics\Source;

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{Annotation, DateRange};
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * AnnotationSourceInterface.
 *
 * Mirrors MetricSourceInterface's extensibility, but for site-wide,
 * point-in-time events rather than per-URL metrics.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
interface AnnotationSourceInterface
{
    public function getIdentifier(): string;

    public function supports(Site $site): bool;

    /**
     * @return iterable<Annotation>
     */
    public function fetch(Site $site, DateRange $range): iterable;
}
