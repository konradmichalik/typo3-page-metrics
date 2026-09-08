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

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{Annotation, DateRange};
use KonradMichalik\Typo3PageMetrics\Source\AnnotationSourceInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * StubAnnotationSource.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class StubAnnotationSource implements AnnotationSourceInterface
{
    /**
     * @param list<Annotation> $annotations
     */
    public function __construct(
        private array $annotations,
    ) {}

    public function getIdentifier(): string
    {
        return 'stub';
    }

    public function supports(Site $site): bool
    {
        return true;
    }

    public function fetch(Site $site, DateRange $range): iterable
    {
        yield from $this->annotations;
    }
}
