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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{TrendDirection, TrendSentiment};

/**
 * BeforeAfterComparison.
 *
 * One metric's total over the days before a marker against its total over
 * the days after it. "14 days before the rewrite: 420 views, 14 days after:
 * 680" is the whole point of the marker package, and it is also the claim
 * that is easiest to make falsely, which is what $isComplete guards.
 *
 * Completeness sits on the row rather than on the block it belongs to
 * because coverage is per provider: a site whose access log was added last
 * week can answer the question for Matomo and not for that log, and one
 * verdict for both would either hide a real comparison or invent a missing
 * one.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class BeforeAfterComparison
{
    /**
     * True where there is no percentage base to compare against, so the row
     * reads "new" rather than a false infinity. Same flag, same reason as
     * MetricTile::$isNew.
     */
    public bool $isNew;

    /**
     * @param bool $isComplete false while either window still contains days nobody has
     *                         imported, in which case the two figures must not be shown
     *                         side by side at all
     */
    public function __construct(
        public string $label,
        public string $formattedBefore,
        public string $formattedAfter,
        public ?int $deltaPercent,
        public TrendDirection $direction,
        public TrendSentiment $sentiment,
        public bool $isComplete,
        public int $availableBeforeDays,
        public int $availableAfterDays,
    ) {
        $this->isNew = null === $deltaPercent;
    }
}
