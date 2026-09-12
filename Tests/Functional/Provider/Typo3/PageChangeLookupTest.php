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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Provider\Typo3;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DateRange;
use KonradMichalik\Typo3PageMetrics\Provider\Typo3\PageChangeLookup;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function sprintf;

/**
 * PageChangeLookupTest.
 *
 * The fixture timestamps are absolute rather than relative to today on
 * purpose: this class asks for an explicit window, so nothing here depends
 * on the day the suite happens to run. 1789041600 is 2026-09-10 12:00 UTC
 * and every later one is a whole number of days after it, each at midday so
 * the server's time zone cannot move a row to the neighbouring day.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class PageChangeLookupTest extends FunctionalTestCase
{
    // typo3/cms-redirects provides the sys_redirect table; it is not part of
    // the core extensions loaded by default, so it must be declared here.
    protected array $coreExtensionsToLoad = ['redirects'];

    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private PageChangeLookup $subject;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_history.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_redirect.csv');
        $this->subject = $this->get(PageChangeLookup::class);
        $this->site = new Site('main', 1, ['base' => 'https://example.com/']);
    }

    public function testFoldsSeveralEditsOfOnePageOnOneDayIntoASingleChange(): void
    {
        $changes = $this->changesByKey();

        // Two edits to the page record plus one to a content element on it.
        self::assertSame(3, $changes['content|2|2026-09-10'] ?? null);
    }

    public function testKeepsEditsOnDifferentDaysApart(): void
    {
        $changes = $this->changesByKey();

        self::assertSame(1, $changes['content|2|2026-09-11'] ?? null);
    }

    public function testCountsAnEditToADeletedContentElement(): void
    {
        // The element is gone, but the day it was removed is exactly the day
        // the page changed.
        $changes = $this->changesByKey();

        self::assertSame(1, $changes['content|2|2026-09-12'] ?? null);
    }

    public function testCountsAnEditToAPageTranslationAgainstThePageItself(): void
    {
        // 2026-09-13 was only touched through the translation record, which is
        // a page of its own in the database but not one any tile asks about.
        $changes = $this->changesByKey();

        self::assertSame(1, $changes['content|2|2026-09-13'] ?? null);
        self::assertArrayNotHasKey('content|3|2026-09-13', $changes);
    }

    public function testIgnoresEditsToAnotherSitesPages(): void
    {
        $changes = $this->changesByKey();

        self::assertArrayNotHasKey('content|11|2026-09-10', $changes);
    }

    public function testIgnoresEditsOutsideTheWindow(): void
    {
        $changes = $this->changesByKey();

        self::assertArrayNotHasKey('content|2|2026-08-26', $changes);
    }

    public function testIgnoresWorkspaceEdits(): void
    {
        // The only live change on 2026-09-12 is the deleted content element,
        // so the workspace edit of the page record on the same day must not
        // raise that day's count.
        $changes = $this->changesByKey();

        self::assertSame(1, $changes['content|2|2026-09-12'] ?? null);
    }

    public function testFoldsSeveralRedirectsCreatedForOnePageOnOneDay(): void
    {
        $changes = $this->changesByKey();

        self::assertSame(2, $changes['seo|2|2026-09-11'] ?? null);
    }

    public function testIgnoresRedirectsPointingSomewhereOtherThanAPageOfThisSite(): void
    {
        $changes = $this->changesByKey();

        self::assertArrayNotHasKey('seo|11|2026-09-11', $changes);
    }

    public function testReturnsNothingForAWindowWithoutActivity(): void
    {
        $changes = $this->subject->changesFor($this->site, new DateRange(
            new DateTimeImmutable('2026-07-01'),
            new DateTimeImmutable('2026-07-31'),
        ));

        self::assertSame([], $changes);
    }

    /**
     * Indexed rather than compared as a list: the queries carry no ORDER BY,
     * so the order rows come back in is the database's business.
     *
     * @return array<string, int>
     */
    private function changesByKey(): array
    {
        $changes = $this->subject->changesFor($this->site, new DateRange(
            new DateTimeImmutable('2026-09-08'),
            new DateTimeImmutable('2026-09-14'),
        ));

        $indexed = [];

        foreach ($changes as $change) {
            $indexed[sprintf('%s|%d|%s', $change->category->value, $change->pageUid, $change->date->format('Y-m-d'))] = $change->changes;
        }

        return $indexed;
    }
}
