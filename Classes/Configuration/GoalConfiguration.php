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

namespace KonradMichalik\Typo3PageMetrics\Configuration;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricPolarity;

/**
 * GoalConfiguration.
 *
 * One goal an operator declared for a site under "pageMetrics.goals".
 *
 * Which goal counts as success is a business question, not a technical one:
 * an analytics account holds dozens of goals, and only the operator knows
 * which of them the editors of this site are meant to act on, what to call
 * it, and whether a rise in it is good news. So a goal is never discovered
 * from the provider's API, it is named here.
 *
 * $id is a provider-local key: Matomo's numeric goal id (or "ecommerceOrder"),
 * GA4's key-event name. It ends up verbatim inside the stored metric
 * identifier, which is why the parser restricts its character set.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class GoalConfiguration
{
    public function __construct(
        public string $provider,
        public string $id,
        public string $label,
        public MetricPolarity $polarity,
        public bool $trackRevenue,
    ) {}
}
