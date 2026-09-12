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

namespace KonradMichalik\Typo3PageMetrics\Provider;

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\MetricDefinition;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * SiteAwareMetricProviderInterface.
 *
 * For a provider whose metric list is not the same everywhere. Goal
 * conversions are the case that brought this about: which goals exist, what
 * they are called and whether a rise in one is good news are all per-site
 * configuration, so no static list can describe them.
 *
 * A separate interface rather than a Site parameter on
 * MetricProviderInterface::getMetricDefinitions(): that interface is `@api`
 * and third-party providers implement it, and PHP would reject every one of
 * them the moment the parameter appeared. Implementing this one is opt-in,
 * and a provider that does not stays exactly as it was.
 *
 * The definitions returned here are additional to the static ones, never a
 * replacement for them.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
interface SiteAwareMetricProviderInterface extends MetricProviderInterface
{
    /**
     * @return list<MetricDefinition>
     */
    public function getSiteMetricDefinitions(Site $site): array;
}
