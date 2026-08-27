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
}
