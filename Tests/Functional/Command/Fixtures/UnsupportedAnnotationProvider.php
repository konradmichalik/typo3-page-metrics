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
use KonradMichalik\Typo3PageMetrics\Provider\AnnotationProviderInterface;
use RuntimeException;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * UnsupportedAnnotationProvider.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class UnsupportedAnnotationProvider implements AnnotationProviderInterface
{
    public function getIdentifier(): string
    {
        return 'unsupported';
    }

    public function supports(Site $site): bool
    {
        return false;
    }

    public function fetch(Site $site, DateRange $range): iterable
    {
        throw new RuntimeException('fetch() must not be called for a provider that does not support the site.', 1756000901);
    }
}
