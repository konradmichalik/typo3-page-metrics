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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Resolver;

use KonradMichalik\Typo3PageMetrics\Resolver\RouteMatcher;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * RouteMatcherTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RouteMatcherTest extends TestCase
{
    private RouteMatcher $subject;

    protected function setUp(): void
    {
        $this->subject = new RouteMatcher();
    }

    public function testSkipsRoutingEntirelyForASiteWithoutRouteEnhancers(): void
    {
        // A unit test on purpose: there is no database and no TYPO3 router
        // here, so this can only pass if the site configuration alone decides
        // it. That is the cost guarantee: a site with no enhancers pays not
        // one extra query per unresolved URL.
        $site = $this->createMock(Site::class);
        $site->method('getConfiguration')->willReturn(['rootPageId' => 1]);

        self::assertNull($this->subject->match($site, null, 'https://example.com/news/my-article', '/news/my-article'));
    }

    public function testSkipsRoutingForAnEmptyRouteEnhancerList(): void
    {
        $site = $this->createMock(Site::class);
        $site->method('getConfiguration')->willReturn(['routeEnhancers' => []]);

        self::assertNull($this->subject->match($site, null, 'https://example.com/news/my-article', '/news/my-article'));
    }
}
