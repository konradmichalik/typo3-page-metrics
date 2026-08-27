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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Persistence;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DateRange;
use KonradMichalik\Typo3PageMetrics\Persistence\AnnotationRepository;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * AnnotationRepositoryTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class AnnotationRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private AnnotationRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = $this->get(AnnotationRepository::class);
    }

    public function testUpsertInsertsNewRow(): void
    {
        $this->subject->upsert('main', 'matomo', '1', new DateTimeImmutable('2026-08-02'), 'Release 2.0');

        $annotations = $this->subject->forSiteAndRange('main', new DateRange(
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-07'),
        ));

        self::assertCount(1, $annotations);
        self::assertSame('2026-08-02', $annotations[0]->date->format('Y-m-d'));
        self::assertSame('Release 2.0', $annotations[0]->text);
    }

    public function testUpsertUpdatesExistingRowInsteadOfDuplicating(): void
    {
        $this->subject->upsert('main', 'matomo', '1', new DateTimeImmutable('2026-08-02'), 'Release 2.0');
        $this->subject->upsert('main', 'matomo', '1', new DateTimeImmutable('2026-08-02'), 'Release 2.0 (edited)');

        $annotations = $this->subject->forSiteAndRange('main', new DateRange(
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-07'),
        ));

        self::assertCount(1, $annotations);
        self::assertSame('Release 2.0 (edited)', $annotations[0]->text);
    }

    public function testForSiteAndRangeFiltersByDateRange(): void
    {
        $this->subject->upsert('main', 'matomo', '1', new DateTimeImmutable('2026-08-02'), 'In range');
        $this->subject->upsert('main', 'matomo', '2', new DateTimeImmutable('2026-08-20'), 'Out of range');

        $annotations = $this->subject->forSiteAndRange('main', new DateRange(
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-07'),
        ));

        self::assertCount(1, $annotations);
        self::assertSame('In range', $annotations[0]->text);
    }

    public function testForSiteAndRangeFiltersBySiteIdentifier(): void
    {
        $this->subject->upsert('main', 'matomo', '1', new DateTimeImmutable('2026-08-02'), 'Main site');
        $this->subject->upsert('other', 'matomo', '1', new DateTimeImmutable('2026-08-02'), 'Other site');

        $annotations = $this->subject->forSiteAndRange('main', new DateRange(
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-07'),
        ));

        self::assertCount(1, $annotations);
        self::assertSame('Main site', $annotations[0]->text);
    }

    public function testForSiteAndRangeReturnsEmptyListWhenNothingMatches(): void
    {
        $annotations = $this->subject->forSiteAndRange('main', new DateRange(
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-07'),
        ));

        self::assertSame([], $annotations);
    }
}
