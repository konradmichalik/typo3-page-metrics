<?php

declare(strict_types=1);

/*
 * This file is part of the "page_metrics" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Resolver;

use KonradMichalik\Typo3PageMetrics\Resolver\SiteMatcher;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * SiteMatcherTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SiteMatcherTest extends TestCase
{
    private SiteMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new SiteMatcher();
    }

    public function testMatchesSiteByExactBaseHost(): void
    {
        $site = new Site('main', 1, ['base' => 'https://example.com/']);

        self::assertSame($site, $this->matcher->match('https://example.com/foo', [$site]));
    }

    public function testMatchesSiteWithSubPathBase(): void
    {
        $site = new Site('main', 1, ['base' => 'https://example.com/sub/']);

        self::assertSame($site, $this->matcher->match('https://example.com/sub/foo', [$site]));
    }

    public function testReturnsNullWhenPathDoesNotMatchSubPathBase(): void
    {
        $site = new Site('main', 1, ['base' => 'https://example.com/sub/']);

        self::assertNull($this->matcher->match('https://example.com/other/foo', [$site]));
    }

    public function testReturnsNullForForeignHost(): void
    {
        $site = new Site('main', 1, ['base' => 'https://example.com/']);

        self::assertNull($this->matcher->match('https://other-host.example/foo', [$site]));
    }

    public function testMatchesSiteByConfiguredAdditionalHost(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://site.ddev.site/',
            'pageMetrics' => ['resolution' => ['additionalHosts' => ['www.live.example']]],
        ]);

        self::assertSame($site, $this->matcher->match('https://www.live.example/foo', [$site]));
    }

    public function testAdditionalHostFromOneSiteDoesNotMatchAnotherSite(): void
    {
        $siteA = new Site('a', 1, [
            'base' => 'https://a.ddev.site/',
            'pageMetrics' => ['resolution' => ['additionalHosts' => ['www.a.example']]],
        ]);
        $siteB = new Site('b', 2, ['base' => 'https://b.ddev.site/']);

        self::assertSame($siteA, $this->matcher->match('https://www.a.example/foo', [$siteA, $siteB]));
        self::assertNull($this->matcher->match('https://www.a.example/foo', [$siteB]));
    }

    public function testReturnsNullWhenNoSitesConfigured(): void
    {
        self::assertNull($this->matcher->match('https://example.com/foo', []));
    }

    public function testPicksFirstMatchingSiteAmongSeveral(): void
    {
        $siteA = new Site('a', 1, ['base' => 'https://a.example.com/']);
        $siteB = new Site('b', 2, ['base' => 'https://b.example.com/']);

        self::assertSame($siteB, $this->matcher->match('https://b.example.com/foo', [$siteA, $siteB]));
    }

    public function testReturnsNullWhenPathSharesPrefixButNotAtSegmentBoundary(): void
    {
        $site = new Site('main', 1, ['base' => 'https://example.com/sub/']);

        self::assertNull($this->matcher->match('https://example.com/subfoo', [$site]));
    }
}
