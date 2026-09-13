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
 * SitePagesCell.
 *
 * One metric's value for one page in the site-wide pages table, in the same
 * order as the table's columns rather than keyed by metric identifier: a
 * metric identifier contains dots, and Fluid reads a dot in a variable path
 * as a step into the object, so a keyed map could not be read back out in
 * the template at all.
 *
 * $hasValue is false only where the metric was never recorded for this page
 * and is not therefore a zero. That distinction only arises for a rate: a
 * count with no stored row genuinely is zero, because the importers write no
 * row for a page that had no traffic (see TopPage::$secondaryFormattedValue),
 * while a ratio with no denominator was divided by nothing and is not a low
 * value but no value.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class SitePagesCell
{
    public function __construct(
        public bool $hasValue,
        public string $formattedValue,
    ) {}
}
