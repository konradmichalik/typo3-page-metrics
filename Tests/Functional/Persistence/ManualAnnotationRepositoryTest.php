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
use KonradMichalik\Typo3PageMetrics\Persistence\ManualAnnotationRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function array_map;
use function sprintf;

/**
 * ManualAnnotationRepositoryTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ManualAnnotationRepositoryTest extends FunctionalTestCase
{
    private const TABLE = 'tx_typo3pagemetrics_manual_annotation';

    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private ManualAnnotationRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = $this->get(ManualAnnotationRepository::class);

        $this->insertAnnotation('main', new DateTimeImmutable('2026-08-02'), 'In range');
        $this->insertAnnotation('main', new DateTimeImmutable('2026-08-20'), 'Out of range');
        $this->insertAnnotation('other', new DateTimeImmutable('2026-08-02'), 'Other site');
        $this->insertAnnotation('main', new DateTimeImmutable('2026-08-02'), 'Deleted', ['deleted' => 1]);
        $this->insertAnnotation('main', new DateTimeImmutable('2026-08-02'), 'Hidden', ['hidden' => 1]);
    }

    public function testForSiteAndRangeFiltersByDateRangeAndSite(): void
    {
        $annotations = $this->subject->forSiteAndRange('main', new DateRange(
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-07'),
        ));

        self::assertCount(1, $annotations);
        self::assertSame('In range', $annotations[0]->text);
        self::assertSame('2026-08-02', $annotations[0]->date->format('Y-m-d'));
    }

    public function testForSiteAndRangeIgnoresDeletedAndHiddenRecords(): void
    {
        $annotations = $this->subject->forSiteAndRange('main', new DateRange(
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-31'),
        ));

        $texts = array_map(static fn ($annotation) => $annotation->text, $annotations);

        self::assertContains('In range', $texts);
        self::assertContains('Out of range', $texts);
        self::assertNotContains('Deleted', $texts);
        self::assertNotContains('Hidden', $texts);
    }

    public function testForSiteAndRangeReturnsEmptyListWhenNothingMatches(): void
    {
        $annotations = $this->subject->forSiteAndRange('unknown-site', new DateRange(
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-07'),
        ));

        self::assertSame([], $annotations);
    }

    public function testReadsBackTheCategoryAndTheRangeTheEditorSet(): void
    {
        $this->insertAnnotation('main', new DateTimeImmutable('2026-08-02'), 'Spring campaign', [
            'category' => 'campaign',
            'date_until' => (new DateTimeImmutable('2026-08-20'))->getTimestamp(),
        ]);

        $annotations = $this->subject->forSiteAndRange('main', new DateRange(
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-31'),
        ));

        // Picked out by its text rather than by position: setUp() already
        // seeds this site and this range, so index 0 is whichever row the
        // repository's own ordering happens to put first.
        $campaign = $this->withText($annotations, 'Spring campaign');

        self::assertSame(AnnotationCategory::Campaign, $campaign->category);
        self::assertSame('2026-08-20', $campaign->dateUntil?->format('Y-m-d'));
    }

    public function testAnEmptyDateUntilIsNoRangeRatherThan1970(): void
    {
        // TCA writes 0 for an empty datetime, not NULL, and 0 as a timestamp
        // is a date in 1970 rather than an absent one.
        $this->insertAnnotation('main', new DateTimeImmutable('2026-08-02'), 'One day', ['date_until' => 0]);

        $annotations = $this->subject->forSiteAndRange('main', new DateRange(
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-07'),
        ));

        $oneDay = $this->withText($annotations, 'One day');

        self::assertNull($oneDay->dateUntil);
        self::assertFalse($oneDay->isRange);
    }

    public function testAPageScopedMarkerOnlyReachesItsOwnPage(): void
    {
        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-07'));
        $this->insertAnnotation('main', new DateTimeImmutable('2026-08-02'), 'Site-wide');
        $this->insertAnnotation('main', new DateTimeImmutable('2026-08-02'), 'About only', ['page_uid' => 42]);

        $onItsOwnPage = $this->textsOf($this->subject->forSiteAndRange('main', $range, 42));
        $onAnother = $this->textsOf($this->subject->forSiteAndRange('main', $range, 99));

        self::assertContains('About only', $onItsOwnPage);
        self::assertNotContains('About only', $onAnother);
        // A marker with no page reaches every page of the site, which is what
        // makes the scoped one a narrowing rather than a replacement.
        self::assertContains('Site-wide', $onItsOwnPage);
        self::assertContains('Site-wide', $onAnother);
    }

    /**
     * @param list<Annotation> $annotations
     *
     * @return list<string>
     */
    private function textsOf(array $annotations): array
    {
        return array_map(static fn (Annotation $annotation): string => $annotation->text, $annotations);
    }

    /**
     * @param list<Annotation> $annotations
     */
    private function withText(array $annotations, string $text): Annotation
    {
        foreach ($annotations as $annotation) {
            if ($text === $annotation->text) {
                return $annotation;
            }
        }

        self::fail(sprintf('No annotation with text "%s" was returned.', $text));
    }

    /**
     * @param array<string, int|string> $overrides
     */
    private function insertAnnotation(string $siteIdentifier, DateTimeImmutable $date, string $text, array $overrides = []): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid' => 0,
            'site_identifier' => $siteIdentifier,
            'date' => $date->getTimestamp(),
            'text' => $text,
            ...$overrides,
        ]);
    }
}
