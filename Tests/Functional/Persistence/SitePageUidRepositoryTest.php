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

    private function permsClauseFor(int $backendUserUid): string
    {
        return $this->setUpBackendUser($backendUserUid)->getPagePermsClause(Permission::PAGE_SHOW);
    }
}
