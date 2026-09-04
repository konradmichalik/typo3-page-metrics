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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Command\Fixtures;

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DateRange;
use KonradMichalik\Typo3PageMetrics\Source\MetricSourceInterface;
use RuntimeException;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * UnsupportedMetricSource.
 *
 * A registered source that is not configured for any site. fetch() throws so a
 * test fails loudly if the import ever reaches it instead of skipping it.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class UnsupportedMetricSource implements MetricSourceInterface
{
    public function getIdentifier(): string
    {
        return 'unsupported';
    }

    public function supports(Site $site): bool
    {
        return false;
    }

    public function getMetricDefinitions(): array
    {
        return [];
    }

    public function getRateMetricDefinitions(): array
    {
        return [];
    }

    public function getExternalLink(Site $site, string $pageUrl, DateRange $range): ?string
    {
        return null;
    }

    public function fetch(Site $site, DateRange $range): iterable
    {
        throw new RuntimeException('fetch() must not be called for a source that does not support the site.', 1756000900);
    }
}
