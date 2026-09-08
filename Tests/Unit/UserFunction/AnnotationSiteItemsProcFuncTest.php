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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\UserFunction;

use KonradMichalik\Typo3PageMetrics\UserFunction\AnnotationSiteItemsProcFunc;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * AnnotationSiteItemsProcFuncTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class AnnotationSiteItemsProcFuncTest extends TestCase
{
    public function testAppendsOneItemPerConfiguredSite(): void
    {
        $subject = new AnnotationSiteItemsProcFunc($this->siteFinderReturning(
            new Site('main', 1, ['base' => 'https://example.com/']),
            new Site('shop', 5, ['base' => 'https://shop.example.com/']),
        ));

        $fieldInformation = ['items' => []];
        $subject->populateSites($fieldInformation);

        self::assertSame([
            ['label' => 'main', 'value' => 'main'],
            ['label' => 'shop', 'value' => 'shop'],
        ], $fieldInformation['items']);
    }

    public function testKeepsItemsThatTcaAlreadyDefined(): void
    {
        $subject = new AnnotationSiteItemsProcFunc($this->siteFinderReturning(
            new Site('main', 1, ['base' => 'https://example.com/']),
        ));

        $fieldInformation = ['items' => [['label' => '', 'value' => '']]];
        $subject->populateSites($fieldInformation);

        self::assertSame([
            ['label' => '', 'value' => ''],
            ['label' => 'main', 'value' => 'main'],
        ], $fieldInformation['items']);
    }

    public function testLeavesTheItemListEmptyWhenNoSiteIsConfigured(): void
    {
        $subject = new AnnotationSiteItemsProcFunc($this->siteFinderReturning());

        $fieldInformation = ['items' => []];
        $subject->populateSites($fieldInformation);

        self::assertSame([], $fieldInformation['items']);
    }

    private function siteFinderReturning(Site ...$sites): SiteFinder
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn($sites);

        return $siteFinder;
    }
}
