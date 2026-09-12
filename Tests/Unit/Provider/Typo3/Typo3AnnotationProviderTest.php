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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Provider\Typo3;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Configuration\SiteConfigurationReader;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\AnnotationCategory;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{Annotation, DateRange};
use KonradMichalik\Typo3PageMetrics\Provider\Typo3\{DailyPageChange, PageChangeLookup, Typo3AnnotationProvider};
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;

use function iterator_to_array;

/**
 * Typo3AnnotationProviderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class Typo3AnnotationProviderTest extends TestCase
{
    /** @var PageChangeLookup&MockObject */
    private PageChangeLookup $pageChangeLookup;
    private Typo3AnnotationProvider $subject;

    protected function setUp(): void
    {
        $this->pageChangeLookup = $this->createMock(PageChangeLookup::class);
        $this->subject = new Typo3AnnotationProvider(new SiteConfigurationReader(), $this->pageChangeLookup);
    }

    public function testGetIdentifierReturnsTypo3(): void
    {
        self::assertSame('typo3', $this->subject->getIdentifier());
    }

    public function testSupportsAnEnabledSite(): void
    {
        self::assertTrue($this->subject->supports($this->site(['enabled' => true])));
    }

    public function testDoesNotSupportASiteThatTurnedAutomaticMarkersOff(): void
    {
        self::assertFalse($this->subject->supports($this->site(['enabled' => true, 'annotations' => ['automatic' => false]])));
    }

    public function testDoesNotSupportASiteWithoutPageMetricsAtAll(): void
    {
        self::assertFalse($this->subject->supports(new Site('main', 1, ['base' => 'https://example.com/'])));
    }

    public function testNarrowsALongRetentionWindowToTheDaysThatAreActuallyDrawn(): void
    {
        $today = new DateTimeImmutable('today');
        $asked = new DateRange($today->modify('-400 days'), $today);

        $this->pageChangeLookup
            ->expects(self::once())
            ->method('changesFor')
            ->with(self::anything(), self::callback(
                static fn (mixed $range): bool => $range instanceof DateRange
                    && $range->from->format('Y-m-d') === $today->modify('-90 days')->format('Y-m-d')
                    && $range->until->format('Y-m-d') === $today->format('Y-m-d'),
            ))
            ->willReturn([]);

        iterator_to_array($this->subject->fetch($this->site(['enabled' => true]), $asked), false);
    }

    public function testLeavesAShorterWindowAlone(): void
    {
        $today = new DateTimeImmutable('today');
        $asked = new DateRange($today->modify('-30 days'), $today);

        $this->pageChangeLookup
            ->expects(self::once())
            ->method('changesFor')
            ->with(self::anything(), self::callback(
                static fn (mixed $range): bool => $range instanceof DateRange
                    && $range->from->format('Y-m-d') === $today->modify('-30 days')->format('Y-m-d'),
            ))
            ->willReturn([]);

        iterator_to_array($this->subject->fetch($this->site(['enabled' => true]), $asked), false);
    }

    public function testTurnsAContentChangeIntoAPageScopedMarker(): void
    {
        $annotation = $this->firstAnnotationFor(new DailyPageChange(7, new DateTimeImmutable('2026-09-10'), 1, AnnotationCategory::Content));

        self::assertSame('content-7-20260910', $annotation->id);
        self::assertSame(AnnotationCategory::Content, $annotation->category);
        self::assertSame(7, $annotation->pageUid);
        self::assertNull($annotation->dateUntil);
        self::assertSame('Page updated', $annotation->text);
    }

    public function testCountsSeveralChangesOfADayInTheMarkerText(): void
    {
        $annotation = $this->firstAnnotationFor(new DailyPageChange(7, new DateTimeImmutable('2026-09-10'), 5, AnnotationCategory::Content));

        self::assertSame('Page updated, 5 changes', $annotation->text);
    }

    public function testTurnsARedirectCreationIntoAnSeoMarker(): void
    {
        $annotation = $this->firstAnnotationFor(new DailyPageChange(7, new DateTimeImmutable('2026-09-10'), 1, AnnotationCategory::Seo));

        self::assertSame('seo-7-20260910', $annotation->id);
        self::assertSame(AnnotationCategory::Seo, $annotation->category);
        self::assertSame('Redirect created', $annotation->text);
    }

    public function testCountsSeveralRedirectsOfADayInTheMarkerText(): void
    {
        $annotation = $this->firstAnnotationFor(new DailyPageChange(7, new DateTimeImmutable('2026-09-10'), 3, AnnotationCategory::Seo));

        self::assertSame('3 redirects created', $annotation->text);
    }

    private function firstAnnotationFor(DailyPageChange $change): Annotation
    {
        $this->pageChangeLookup->method('changesFor')->willReturn([$change]);

        $today = new DateTimeImmutable('today');
        $annotations = iterator_to_array(
            $this->subject->fetch($this->site(['enabled' => true]), new DateRange($today->modify('-30 days'), $today)),
            false,
        );

        return $annotations[0];
    }

    /**
     * @param array<string, mixed> $pageMetrics
     */
    private function site(array $pageMetrics): Site
    {
        return new Site('main', 1, ['base' => 'https://example.com/', 'pageMetrics' => $pageMetrics]);
    }
}
