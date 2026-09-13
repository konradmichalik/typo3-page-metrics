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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Domain\ValueObject;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\AnnotationCategory;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\Annotation;
use PHPUnit\Framework\TestCase;

/**
 * AnnotationTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class AnnotationTest extends TestCase
{
    public function testConstructorAssignsAllProperties(): void
    {
        $date = new DateTimeImmutable('2026-08-01');
        $until = new DateTimeImmutable('2026-08-20');
        $annotation = new Annotation('1', $date, 'Spring campaign', AnnotationCategory::Campaign, $until, 42, true);

        self::assertSame('1', $annotation->id);
        self::assertSame($date, $annotation->date);
        self::assertSame('Spring campaign', $annotation->text);
        self::assertSame(AnnotationCategory::Campaign, $annotation->category);
        self::assertSame($until, $annotation->dateUntil);
        self::assertSame(42, $annotation->pageUid);
        self::assertTrue($annotation->includeSubpages);
    }

    public function testAMarkerStandsForItselfUnlessItSaysHowManyItStandsFor(): void
    {
        $date = new DateTimeImmutable('2026-08-01');

        self::assertSame(0, (new Annotation('1', $date, 'Release 2.0'))->collapsedCount);
        self::assertSame(14, (new Annotation('c', $date, '14 pages updated', AnnotationCategory::Content, null, 0, false, 14))->collapsedCount);
    }

    public function testDefaultsToASiteWideSingleDayRelease(): void
    {
        // What every annotation in this extension was before it could be
        // anything else, so a provider written against the old shape keeps
        // producing exactly what it used to.
        $annotation = new Annotation('1', new DateTimeImmutable('2026-08-01'), 'Release 2.0');

        self::assertSame(AnnotationCategory::Release, $annotation->category);
        self::assertNull($annotation->dateUntil);
        self::assertSame(0, $annotation->pageUid);
        self::assertFalse($annotation->includeSubpages);
        self::assertFalse($annotation->isRange);
    }

    public function testAnEndDateOnTheStartDayIsNotARange(): void
    {
        // A one-day event stored with both ends set is still a tick, not a
        // band of zero width.
        $date = new DateTimeImmutable('2026-08-01');
        $annotation = new Annotation('1', $date, 'Release', AnnotationCategory::Release, $date);

        self::assertFalse($annotation->isRange);
    }

    public function testExposesTheCategorysIconAndLabelForTemplates(): void
    {
        $annotation = new Annotation('1', new DateTimeImmutable('2026-08-01'), 'Outage', AnnotationCategory::Incident);

        self::assertSame(AnnotationCategory::Incident->icon(), $annotation->getCategoryIcon());
        self::assertSame(AnnotationCategory::Incident->label(), $annotation->getCategoryLabel());
    }
}
