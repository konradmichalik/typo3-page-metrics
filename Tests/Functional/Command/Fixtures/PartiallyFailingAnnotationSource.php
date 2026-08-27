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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Command\Fixtures;

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DateRange;
use KonradMichalik\Typo3PageMetrics\Source\AnnotationSourceInterface;
use RuntimeException;
use TYPO3\CMS\Core\Site\Entity\Site;

use function sprintf;

/**
 * PartiallyFailingAnnotationSource.
 *
 * Always throws when fetched, used to prove that an annotation source
 * failure does not abort metric import for the same site.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class PartiallyFailingAnnotationSource implements AnnotationSourceInterface
{
    public function getIdentifier(): string
    {
        return 'failing-annotations';
    }

    public function supports(Site $site): bool
    {
        return true;
    }

    public function fetch(Site $site, DateRange $range): iterable
    {
        throw new RuntimeException(sprintf('Simulated annotation source failure for site "%s".', $site->getIdentifier()));
    }
}
