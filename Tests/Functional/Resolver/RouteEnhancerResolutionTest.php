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
 * RouteEnhancerResolutionTest.
 *
 * Covers the record detail URLs a route enhancer produces: /news/my-article
 * is rendered by page 3 (/news) but matches no page slug of its own. The
 * discriminating case is the second test: /news/does-not-exist looks exactly
 * the same to a prefix match, and must stay unresolved.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RouteEnhancerResolutionTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private Resolver $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/route-enhancer-pages.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/route-enhancer-records.csv');
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
            ],
            'errorHandling' => [],
            'routes' => [],
            // Stands in for the enhancer a news, products or events extension
            // ships: a detail segment below /news, mapped to rows of a real
            // table. The table is this extension's own manual annotation
            // table purely because it is the one guaranteed to exist with TCA
            // in a functional test; what matters is that the mapper has to hit
            // the database to decide whether a segment names a record.
            'routeEnhancers' => [
                'Articles' => [
                    'type' => 'Simple',
                    'limitToPages' => [3],
                    'routePath' => '/{article}',
                    'aspects' => [
                        'article' => [
                            'type' => 'PersistedAliasMapper',
                            'tableName' => 'tx_typo3pagemetrics_manual_annotation',
                            'routeFieldName' => 'text',
                        ],
                    ],
                ],
            ],
        ]);
        $this->subject = $this->get(Resolver::class);
    }

    public function testAttributesARecordDetailUrlToThePageThatRendersIt(): void
    {
        $result = $this->subject->resolve('https://example.com/news/my-article');

        self::assertInstanceOf(ResolvedUrl::class, $result);
        self::assertSame(3, $result->pageUid);
        self::assertSame(0, $result->languageUid);
        self::assertTrue($result->viaRouteEnhancer);
        self::assertFalse($result->viaRedirect);
    }

    public function testCarriesTheRecordTheRouteEnhancerMatched(): void
    {
        // The mapper looked the segment up in its table to decide the route
        // matches at all, so the uid is already in PageArguments. Which table
        // it belongs to comes from the enhancer's own aspect configuration,
        // which costs nothing to read back.
        $result = $this->subject->resolve('https://example.com/news/my-article');

        self::assertInstanceOf(ResolvedUrl::class, $result);
        self::assertNotNull($result->record);
        self::assertSame('tx_typo3pagemetrics_manual_annotation', $result->record->table);
        self::assertSame(1, $result->record->uid);
    }

    public function testCarriesNoRecordForAPageThatIsItsOwnUrl(): void
    {
        $result = $this->subject->resolve('https://example.com/news');

        self::assertInstanceOf(ResolvedUrl::class, $result);
        self::assertNull($result->record);
    }

    public function testLeavesABotProbeUnresolvedWithoutEverAskingTheRouter(): void
    {
        // The router is the most expensive step in the chain and an access
        // log feeds it thousands of distinct probe paths a night. None of
        // them hangs below a real page, which is the cheap question that
        // keeps them from reaching it at all.
        $result = $this->subject->resolve('https://example.com/wp-login.php');

        self::assertInstanceOf(UnresolvedUrl::class, $result);
        self::assertSame(UnresolvedReason::Unknown, $result->reason);
    }

    public function testKeepsAUrlBelowTheSamePageUnresolvedWhenNoRecordMatches(): void
    {
        // The whole point of the router over a longest-prefix slug match: this
        // URL has the same shape as the one above and the same ancestor page,
        // and must still be reported as unresolved so the "Not found pages"
        // report keeps surfacing it.
        $result = $this->subject->resolve('https://example.com/news/does-not-exist');

        self::assertInstanceOf(UnresolvedUrl::class, $result);
        self::assertSame('https://example.com/news/does-not-exist', $result->url);
        self::assertSame(UnresolvedReason::Unknown, $result->reason);
    }

    public function testStillResolvesAPlainPageSlugWithoutClaimingARouteEnhancer(): void
    {
        $result = $this->subject->resolve('https://example.com/news');

        self::assertInstanceOf(ResolvedUrl::class, $result);
        self::assertSame(3, $result->pageUid);
        self::assertFalse($result->viaRouteEnhancer);
    }

    public function testDoesNotRouteAFilePathThatLooksLikeARecordUrl(): void
    {
        // Classification wins over the router: an asset below /fileadmin is
        // never a record URL, and running the router on it would cost queries
        // for a reason already known.
        $result = $this->subject->resolve('https://example.com/fileadmin/news/my-article.jpg');

        self::assertInstanceOf(UnresolvedUrl::class, $result);
        self::assertSame(UnresolvedReason::File, $result->reason);
    }
}
