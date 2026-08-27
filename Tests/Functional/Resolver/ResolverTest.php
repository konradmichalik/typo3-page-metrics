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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Resolver;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\UnresolvedReason;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{ResolvedUrl, UnresolvedUrl};
use KonradMichalik\Typo3PageMetrics\Resolver\Resolver;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * ResolverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ResolverTest extends FunctionalTestCase
{
    // typo3/cms-redirects provides the sys_redirect table; it is not part of
    // the core extensions loaded by default, so it must be declared here.
    protected array $coreExtensionsToLoad = ['redirects'];

    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private Resolver $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/resolver-pages.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_redirect.csv');
        // TYPO3 v14's testing-framework 9.x dropped SiteBasedTestTrait; the
        // core SiteWriter service is the direct replacement for building and
        // persisting a multi-language site configuration in functional tests.
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'languages' => [
                0 => [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'navigationTitle' => 'English',
                    'flag' => 'us',
                ],
                1 => [
                    'title' => 'German',
                    'enabled' => true,
                    'languageId' => 1,
                    'base' => '/en/',
                    'locale' => 'de_DE.UTF-8',
                    'navigationTitle' => 'German',
                    'fallbackType' => 'strict',
                    'fallbacks' => '0',
                    'flag' => 'de',
                ],
            ],
            'errorHandling' => [],
            'routes' => [],
        ]);
        $this->subject = $this->get(Resolver::class);
    }

    public function testResolvesDefaultLanguagePageBySlug(): void
    {
        $result = $this->subject->resolve('https://example.com/about');

        self::assertInstanceOf(ResolvedUrl::class, $result);
        self::assertSame(2, $result->pageUid);
        self::assertSame(0, $result->languageUid);
        self::assertFalse($result->viaRedirect);
    }

    public function testResolvesTranslatedPageByStrippingLanguageBasePath(): void
    {
        $result = $this->subject->resolve('https://example.com/en/about');

        self::assertInstanceOf(ResolvedUrl::class, $result);
        self::assertSame(2, $result->pageUid);
        self::assertSame(1, $result->languageUid);
    }

    public function testResolvesThroughARedirectAndMarksViaRedirect(): void
    {
        $result = $this->subject->resolve('https://example.com/old-about');

        self::assertInstanceOf(ResolvedUrl::class, $result);
        self::assertSame(2, $result->pageUid);
        self::assertTrue($result->viaRedirect);
    }

    public function testReturnsForeignHostForUnknownDomain(): void
    {
        $result = $this->subject->resolve('https://not-configured.example/foo');

        self::assertInstanceOf(UnresolvedUrl::class, $result);
        self::assertSame(UnresolvedReason::ForeignHost, $result->reason);
    }

    public function testReturnsFileForFileadminPath(): void
    {
        $result = $this->subject->resolve('https://example.com/fileadmin/images/foo.jpg');

        self::assertInstanceOf(UnresolvedUrl::class, $result);
        self::assertSame(UnresolvedReason::File, $result->reason);
    }

    public function testReturnsUnknownForUnmatchedPath(): void
    {
        $result = $this->subject->resolve('https://example.com/gone-page');

        self::assertInstanceOf(UnresolvedUrl::class, $result);
        self::assertSame(UnresolvedReason::Unknown, $result->reason);
    }

    public function testMutualRedirectCycleResolvesToUnknownWithinFiveHops(): void
    {
        $result = $this->subject->resolve('https://example.com/redirect-a');

        self::assertInstanceOf(UnresolvedUrl::class, $result);
        self::assertSame(UnresolvedReason::Unknown, $result->reason);
    }

    public function testCachesResultForIdenticalUrlWithinOneRun(): void
    {
        $first = $this->subject->resolve('https://example.com/about');
        $second = $this->subject->resolve('https://example.com/about');

        self::assertSame($first, $second);
    }

    public function testResetClearsTheCache(): void
    {
        $first = $this->subject->resolve('https://example.com/about');
        $this->subject->reset();
        $second = $this->subject->resolve('https://example.com/about');

        self::assertNotSame($first, $second);
    }

    public function testReturnsNonPageForTypo3BackendPath(): void
    {
        $result = $this->subject->resolve('https://example.com/typo3/module/web/layout');

        self::assertInstanceOf(UnresolvedUrl::class, $result);
        self::assertSame(UnresolvedReason::NonPage, $result->reason);
    }

    public function testReportsOriginalUrlWhenRedirectTargetIsUnresolvable(): void
    {
        // /old-external redirects to https://example.com/new-external, which
        // matches no page slug and no further redirect. The reported
        // UnresolvedUrl must carry the URL that was actually imported
        // (/old-external), not the intermediate redirect target, so later
        // tasks can join the unresolved report back to source rows.
        $result = $this->subject->resolve('https://example.com/old-external');

        self::assertInstanceOf(UnresolvedUrl::class, $result);
        self::assertSame('https://example.com/old-external', $result->url);
        self::assertSame(UnresolvedReason::Unknown, $result->reason);
    }

    public function testFollowsExactlyFiveRedirectHopsToResolveAPage(): void
    {
        // /five-hop-1 -> ... -> /five-hop-5 -> t3://page?uid=2 is exactly
        // 5 redirect hops. If the cap were off by one (as it was: comparing
        // against the visited-URL count, which includes the un-hopped
        // origin), this would incorrectly give up one hop early and never
        // reach the page.
        $result = $this->subject->resolve('https://example.com/five-hop-1');

        self::assertInstanceOf(ResolvedUrl::class, $result);
        self::assertSame(2, $result->pageUid);
        self::assertTrue($result->viaRedirect);
    }

    public function testGivesUpAfterExceedingFiveRedirectHopCap(): void
    {
        // /six-hop-1 -> ... -> /six-hop-6 -> t3://page?uid=2 needs 6 hops,
        // one more than the cap allows, so resolution must give up before
        // reaching the page. The reported URL must still be the original
        // /six-hop-1 URL, not whichever intermediate hop the cap was hit on.
        $result = $this->subject->resolve('https://example.com/six-hop-1');

        self::assertInstanceOf(UnresolvedUrl::class, $result);
        self::assertSame('https://example.com/six-hop-1', $result->url);
        self::assertSame(UnresolvedReason::Unknown, $result->reason);
    }
}
