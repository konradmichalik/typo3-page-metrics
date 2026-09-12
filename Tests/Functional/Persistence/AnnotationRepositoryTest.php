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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Persistence;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\AnnotationCategory;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{Annotation, DateRange};
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
        $this->subject->upsert('main', 'matomo', new Annotation('1', new DateTimeImmutable('2026-08-02'), 'Release 2.0'));

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
        $this->subject->upsert('main', 'matomo', new Annotation('1', new DateTimeImmutable('2026-08-02'), 'Release 2.0'));
        $this->subject->upsert('main', 'matomo', new Annotation('1', new DateTimeImmutable('2026-08-02'), 'Release 2.0 (edited)'));

        $annotations = $this->subject->forSiteAndRange('main', new DateRange(
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-07'),
        ));

        self::assertCount(1, $annotations);
        self::assertSame('Release 2.0 (edited)', $annotations[0]->text);
    }

    public function testForSiteAndRangeFiltersByDateRange(): void
    {
        $this->subject->upsert('main', 'matomo', new Annotation('1', new DateTimeImmutable('2026-08-02'), 'In range'));
        $this->subject->upsert('main', 'matomo', new Annotation('2', new DateTimeImmutable('2026-08-20'), 'Out of range'));

        $annotations = $this->subject->forSiteAndRange('main', new DateRange(
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-07'),
        ));

        self::assertCount(1, $annotations);
        self::assertSame('In range', $annotations[0]->text);
    }

    public function testForSiteAndRangeFiltersBySiteIdentifier(): void
    {
        $this->subject->upsert('main', 'matomo', new Annotation('1', new DateTimeImmutable('2026-08-02'), 'Main site'));
        $this->subject->upsert('other', 'matomo', new Annotation('1', new DateTimeImmutable('2026-08-02'), 'Other site'));

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

    public function testStoresAndReadsBackTheCategoryAndTheRange(): void
    {
        $this->subject->upsert('main', 'matomo', new Annotation(
            '1',
            new DateTimeImmutable('2026-08-02'),
            'Spring campaign',
            AnnotationCategory::Campaign,
            new DateTimeImmutable('2026-08-20'),
        ));

        $annotations = $this->subject->forSiteAndRange('main', new DateRange(
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-31'),
        ));

        self::assertCount(1, $annotations);
        self::assertSame(AnnotationCategory::Campaign, $annotations[0]->category);
        self::assertSame('2026-08-20', $annotations[0]->dateUntil?->format('Y-m-d'));
        self::assertTrue($annotations[0]->isRange);
    }

    public function testAMarkerStillRunningFromBeforeThePeriodIsIncluded(): void
    {
        // It began last month and has not ended, so it explains this month's
        // numbers. Matching only on the start date would drop it.
        $this->subject->upsert('main', 'matomo', new Annotation(
            '1',
            new DateTimeImmutable('2026-07-10'),
            'Long campaign',
            AnnotationCategory::Campaign,
            new DateTimeImmutable('2026-08-15'),
        ));

        $annotations = $this->subject->forSiteAndRange('main', new DateRange(
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-31'),
        ));

        self::assertCount(1, $annotations);
        self::assertSame('Long campaign', $annotations[0]->text);
    }

    public function testAMarkerThatEndedBeforeThePeriodIsNotIncluded(): void
    {
        $this->subject->upsert('main', 'matomo', new Annotation(
            '1',
            new DateTimeImmutable('2026-06-01'),
            'Old campaign',
            AnnotationCategory::Campaign,
            new DateTimeImmutable('2026-06-20'),
        ));

        self::assertSame([], $this->subject->forSiteAndRange('main', new DateRange(
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-31'),
        )));
    }

    public function testAPageScopedMarkerOnlyReachesItsOwnPage(): void
    {
        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-07'));
        $this->subject->upsert('main', 'matomo', new Annotation('site', new DateTimeImmutable('2026-08-02'), 'Site-wide'));
        $this->subject->upsert('main', 'matomo', new Annotation('page', new DateTimeImmutable('2026-08-02'), 'About only', AnnotationCategory::Content, null, 42));

        $onThatPage = $this->subject->forSiteAndRange('main', $range, 42);
        $elsewhere = $this->subject->forSiteAndRange('main', $range, 99);

        self::assertSame(['Site-wide', 'About only'], array_map(static fn ($a): string => $a->text, $onThatPage));
        self::assertSame(['Site-wide'], array_map(static fn ($a): string => $a->text, $elsewhere));
    }

    public function testAnAncestorsMarkerReachesDownOnlyWhenItSaysSo(): void
    {
        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-07'));
        $this->subject->upsert('main', 'matomo', new Annotation('subtree', new DateTimeImmutable('2026-08-02'), 'Section relaunch', AnnotationCategory::Redesign, null, 10, true));
        $this->subject->upsert('main', 'matomo', new Annotation('pageonly', new DateTimeImmutable('2026-08-02'), 'That one page', AnnotationCategory::Content, null, 11));

        // Page 42, sitting under 11, which sits under 10. Only the one that
        // said it covers its subtree reaches down here.
        $annotations = $this->subject->forSiteAndRange('main', $range, 42, [11, 10]);

        self::assertSame(['Section relaunch'], array_map(static fn ($a): string => $a->text, $annotations));
    }

    public function testWithoutAPageEverythingComesBack(): void
    {
        // Which is what the site-wide dashboard asks for.
        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-07'));
        $this->subject->upsert('main', 'matomo', new Annotation('site', new DateTimeImmutable('2026-08-02'), 'Site-wide'));
        $this->subject->upsert('main', 'matomo', new Annotation('page', new DateTimeImmutable('2026-08-02'), 'One page', AnnotationCategory::Content, null, 42));

        self::assertCount(2, $this->subject->forSiteAndRange('main', $range));
    }
}
