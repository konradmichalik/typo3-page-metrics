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

use KonradMichalik\Typo3PageMetrics\Persistence\SitePageUidRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * SitePageUidRepositoryTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SitePageUidRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private SitePageUidRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/be_users.csv');
        $this->subject = $this->get(SitePageUidRepository::class);
    }

    public function testHasChildrenReturnsTrueWhenANonDeletedChildExists(): void
    {
        self::assertTrue($this->subject->hasChildren(1));
    }

    public function testHasChildrenReturnsFalseForALeafPage(): void
    {
        self::assertFalse($this->subject->hasChildren(2));
    }

    public function testHasChildrenIgnoresDeletedChildren(): void
    {
        self::assertFalse($this->subject->hasChildren(3));
    }

    public function testDescendantsAtLevelReturnsDirectChildrenAtLevelOne(): void
    {
        self::assertSame([2, 5], $this->subject->descendantsAtLevel(1, 1));
    }

    public function testDescendantsAtLevelReturnsGrandchildrenAtLevelTwo(): void
    {
        self::assertSame([6], $this->subject->descendantsAtLevel(1, 2));
    }

    public function testDescendantsAtLevelReturnsEmptyArrayBeyondTheTreesDepth(): void
    {
        self::assertSame([], $this->subject->descendantsAtLevel(1, 3));
    }

    public function testDescendantsAtLevelIgnoresDeletedChildren(): void
    {
        self::assertSame([], $this->subject->descendantsAtLevel(3, 1));
    }

    public function testReadableUidsKeepsEveryPageForAnAdministrator(): void
    {
        self::assertSame([1, 2, 5, 6], $this->subject->readableUids([1, 2, 5, 6], $this->permsClauseFor(1)));
    }

    public function testReadableUidsDropsAPageTheUserMayNotSee(): void
    {
        // Page 2 is the one with perms_everybody = 0 in the fixture, and the
        // editor is in no group, so it is the only page of the four that the
        // page tree would not show them either.
        self::assertSame([1, 5, 6], $this->subject->readableUids([1, 2, 5, 6], $this->permsClauseFor(2)));
    }

    public function testReadableUidsDropsADeletedPage(): void
    {
        self::assertSame([3], $this->subject->readableUids([3, 4], $this->permsClauseFor(1)));
    }

    public function testReadableUidsReturnsNothingForAnEmptyRequest(): void
    {
        self::assertSame([], $this->subject->readableUids([], $this->permsClauseFor(1)));
    }

    public function testLastChangedAtReportsThePageRecordsOwnTimestamp(): void
    {
        $this->touchPage(2, 1_600_000_000);

        self::assertSame([2 => 1_600_000_000], $this->subject->lastChangedAt([2]));
    }

    public function testLastChangedAtPrefersANewerContentElementOverAnOlderPageRecord(): void
    {
        // The case the whole method exists for: somebody rewrote the text of
        // a page without the page record itself moving, and a decay list
        // built on pages.tstamp alone would send an editor to rewrite a page
        // that was rewritten last week.
        $this->touchPage(2, 1_600_000_000);
        $this->addContentElement(2, 1_700_000_000);

        self::assertSame([2 => 1_700_000_000], $this->subject->lastChangedAt([2]));
    }

    public function testLastChangedAtKeepsThePageRecordWhenItIsTheNewerOfTheTwo(): void
    {
        $this->touchPage(2, 1_700_000_000);
        $this->addContentElement(2, 1_600_000_000);

        self::assertSame([2 => 1_700_000_000], $this->subject->lastChangedAt([2]));
    }

    public function testLastChangedAtIgnoresDeletedContentElements(): void
    {
        $this->touchPage(2, 1_600_000_000);
        $this->addContentElement(2, 1_700_000_000, true);

        self::assertSame([2 => 1_600_000_000], $this->subject->lastChangedAt([2]));
    }

    public function testLastChangedAtReportsNothingForADeletedPageEvenWithLivingContentOnIt(): void
    {
        $this->addContentElement(4, 1_700_000_000);

        self::assertSame([], $this->subject->lastChangedAt([4]));
    }

    public function testLastChangedAtAnswersForEveryPageItWasAskedAboutInOneCall(): void
    {
        $this->touchPage(1, 1_500_000_000);
        $this->touchPage(2, 1_600_000_000);

        self::assertSame([1 => 1_500_000_000, 2 => 1_600_000_000], $this->subject->lastChangedAt([1, 2]));
    }

    public function testLastChangedAtReturnsNothingForAnEmptyRequest(): void
    {
        self::assertSame([], $this->subject->lastChangedAt([]));
    }

    public function testAHiddenContentElementStillCountsAsAnEdit(): void
    {
        // The edit that matters most is an editor taking the last element on
        // a page offline. A QueryBuilder arrives with the default
        // restrictions, which drop hidden rows, so without removing them that
        // one edit is the one edit the age never sees and the page goes on
        // reporting a silence it does not have.
        $this->addContentElement(2, 1_760_000_000, false, true);

        self::assertSame(1_760_000_000, $this->subject->lastChangedAt([2])[2] ?? null);
    }

    public function testAnExpiredContentElementStillCountsAsAnEdit(): void
    {
        // Same reason, different default restriction: a page whose content
        // was scheduled to stop showing was edited when that was decided.
        $this->addContentElement(2, 1_760_000_000, false, false, 1);

        self::assertSame(1_760_000_000, $this->subject->lastChangedAt([2])[2] ?? null);
    }

    private function permsClauseFor(int $backendUserUid): string
    {
        return $this->setUpBackendUser($backendUserUid)->getPagePermsClause(Permission::PAGE_SHOW);
    }

    /**
     * Written straight into the row rather than into the fixture, because
     * the fixture is shared with the tests above and a timestamp only means
     * something relative to the moment a test runs.
     */
    private function touchPage(int $pageUid, int $timestamp): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['tstamp' => $timestamp], ['uid' => $pageUid]);
    }

    private function addContentElement(int $pageUid, int $timestamp, bool $deleted = false, bool $hidden = false, int $endTime = 0): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')->insert('tt_content', [
            'pid' => $pageUid,
            'tstamp' => $timestamp,
            'deleted' => $deleted ? 1 : 0,
            'hidden' => $hidden ? 1 : 0,
            'endtime' => $endTime,
            'header' => 'Content on page '.$pageUid,
        ]);
    }
}
