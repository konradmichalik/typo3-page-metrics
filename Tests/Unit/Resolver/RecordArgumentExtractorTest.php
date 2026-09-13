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

use KonradMichalik\Typo3PageMetrics\Resolver\{RecordArgumentExtractor, RouteEnhancerRecordTables};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * RecordArgumentExtractorTest.
 *
 * Unit tests on purpose: there is no database here, so every one of these
 * passing is the proof that reading the record out of a matched route costs
 * no query at all. The uid was already looked up by the mapper inside
 * matchRequest(), and which table it belongs to is in the site configuration
 * the router was loaded with.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RecordArgumentExtractorTest extends TestCase
{
    private RecordArgumentExtractor $subject;

    protected function setUp(): void
    {
        $this->subject = new RecordArgumentExtractor(new RouteEnhancerRecordTables());
    }

    public function testReadsTheRecordFromAnExtbasePluginEnhancer(): void
    {
        $site = $this->siteWithEnhancers([
            'News' => [
                'type' => 'Extbase',
                'extension' => 'News',
                'plugin' => 'Pi1',
                'routes' => [
                    ['routePath' => '/{news_title}', '_controller' => 'News::detail', '_arguments' => ['news_title' => 'news']],
                ],
                'aspects' => [
                    'news_title' => [
                        'type' => 'PersistedAliasMapper',
                        'tableName' => 'tx_news_domain_model_news',
                        'routeFieldName' => 'path_segment',
                    ],
                ],
            ],
        ]);

        $record = $this->subject->extract($site, new PageArguments(3, '0', [
            'tx_news_pi1' => ['controller' => 'News', 'action' => 'detail', 'news' => '42'],
        ]));

        self::assertNotNull($record);
        self::assertSame('tx_news_domain_model_news', $record->table);
        self::assertSame(42, $record->uid);
    }

    public function testReadsTheRecordFromASimpleEnhancerWithoutArgumentMapping(): void
    {
        $site = $this->siteWithEnhancers([
            'Articles' => [
                'type' => 'Simple',
                'limitToPages' => [3],
                'routePath' => '/{article}',
                'aspects' => [
                    'article' => ['type' => 'PersistedAliasMapper', 'tableName' => 'tx_acme_article', 'routeFieldName' => 'slug'],
                ],
            ],
        ]);

        $record = $this->subject->extract($site, new PageArguments(3, '0', ['article' => '7']));

        self::assertNotNull($record);
        self::assertSame('tx_acme_article', $record->table);
        self::assertSame(7, $record->uid);
    }

    public function testIgnoresAnEnhancerThatIsLimitedToOtherPages(): void
    {
        // limitToPages is the router's own scoping, so an enhancer that could
        // not have produced this match must not lend it a table name either.
        $site = $this->siteWithEnhancers([
            'Articles' => [
                'type' => 'Simple',
                'limitToPages' => [9],
                'routePath' => '/{article}',
                'aspects' => [
                    'article' => ['type' => 'PersistedAliasMapper', 'tableName' => 'tx_acme_article', 'routeFieldName' => 'slug'],
                ],
            ],
        ]);

        self::assertNull($this->subject->extract($site, new PageArguments(3, '0', ['article' => '7'])));
    }

    public function testIgnoresAnAspectThatNamesNoTable(): void
    {
        // A StaticValueMapper maps a segment to a value, not to a record.
        $site = $this->siteWithEnhancers([
            'Pagination' => [
                'type' => 'Simple',
                'routePath' => '/page-{page}',
                'aspects' => [
                    'page' => ['type' => 'StaticRangeMapper', 'start' => '1', 'end' => '100'],
                ],
            ],
        ]);

        self::assertNull($this->subject->extract($site, new PageArguments(3, '0', ['page' => '4'])));
    }

    public function testRefusesToGuessWhenTwoRecordArgumentsMatched(): void
    {
        // /{category}/{article} maps to two records and the URL says nothing
        // about which one the page is showing. Attributing the traffic to the
        // wrong table is worse than not attributing it: the numbers would be
        // wrong on a tile that claims to be about a specific record, and
        // nothing downstream could tell.
        $site = $this->siteWithEnhancers([
            'Articles' => [
                'type' => 'Simple',
                'routePath' => '/{category}/{article}',
                'aspects' => [
                    'category' => ['type' => 'PersistedAliasMapper', 'tableName' => 'tx_acme_category', 'routeFieldName' => 'slug'],
                    'article' => ['type' => 'PersistedAliasMapper', 'tableName' => 'tx_acme_article', 'routeFieldName' => 'slug'],
                ],
            ],
        ]);

        self::assertNull($this->subject->extract($site, new PageArguments(3, '0', ['category' => '2', 'article' => '7'])));
    }

    public function testRefusesAnArgumentNameTwoEnhancersClaimForDifferentTables(): void
    {
        $site = $this->siteWithEnhancers([
            'Articles' => [
                'type' => 'Simple',
                'routePath' => '/{item}',
                'aspects' => [
                    'item' => ['type' => 'PersistedAliasMapper', 'tableName' => 'tx_acme_article', 'routeFieldName' => 'slug'],
                ],
            ],
            'Events' => [
                'type' => 'Simple',
                'routePath' => '/{item}',
                'aspects' => [
                    'item' => ['type' => 'PersistedAliasMapper', 'tableName' => 'tx_acme_event', 'routeFieldName' => 'slug'],
                ],
            ],
        ]);

        self::assertNull($this->subject->extract($site, new PageArguments(3, '0', ['item' => '7'])));
    }

    public function testAcceptsTheSameTableClaimedByTwoEnhancers(): void
    {
        $site = $this->siteWithEnhancers([
            'Articles' => [
                'type' => 'Simple',
                'routePath' => '/{item}',
                'aspects' => [
                    'item' => ['type' => 'PersistedAliasMapper', 'tableName' => 'tx_acme_article', 'routeFieldName' => 'slug'],
                ],
            ],
            'ArticlesArchive' => [
                'type' => 'Simple',
                'limitToPages' => [3],
                'routePath' => '/archive/{item}',
                'aspects' => [
                    'item' => ['type' => 'PersistedPatternMapper', 'tableName' => 'tx_acme_article', 'routeFieldPattern' => '^(?P<uid>\\d+)$', 'routeFieldResult' => '{uid}'],
                ],
            ],
        ]);

        $record = $this->subject->extract($site, new PageArguments(3, '0', ['item' => '7']));

        self::assertNotNull($record);
        self::assertSame('tx_acme_article', $record->table);
    }

    public function testIgnoresAnArgumentThatIsNotARecordUid(): void
    {
        $site = $this->siteWithEnhancers([
            'Articles' => [
                'type' => 'Simple',
                'routePath' => '/{article}',
                'aspects' => [
                    'article' => ['type' => 'PersistedAliasMapper', 'tableName' => 'tx_acme_article', 'routeFieldName' => 'slug'],
                ],
            ],
        ]);

        // An unresolved mapper leaves the raw segment in place rather than a
        // uid, and a uid of 0 is no record either.
        self::assertNull($this->subject->extract($site, new PageArguments(3, '0', ['article' => 'my-article'])));
        self::assertNull($this->subject->extract($site, new PageArguments(3, '0', ['article' => '0'])));
    }

    public function testRejectsATableNameThatIsNotAnIdentifier(): void
    {
        // The table name travels from site configuration into a database
        // column and, later, into a query against that very table. Nothing
        // downstream re-checks it, so it is checked here.
        $site = $this->siteWithEnhancers([
            'Articles' => [
                'type' => 'Simple',
                'routePath' => '/{article}',
                'aspects' => [
                    'article' => ['type' => 'PersistedAliasMapper', 'tableName' => 'tx_acme_article; DROP TABLE pages', 'routeFieldName' => 'slug'],
                ],
            ],
        ]);

        self::assertNull($this->subject->extract($site, new PageArguments(3, '0', ['article' => '7'])));
    }

    public function testSurvivesAMalformedRouteEnhancerSection(): void
    {
        // Same stance as RouteMatcher: one broken site configuration reports
        // no record, it does not abort an import run.
        $site = $this->siteWithEnhancers(['Broken' => 'not-an-array']);

        self::assertNull($this->subject->extract($site, new PageArguments(3, '0', ['article' => '7'])));
    }

    public function testReturnsNullForASiteWithoutRouteEnhancers(): void
    {
        $site = $this->createMock(Site::class);
        $site->method('getConfiguration')->willReturn(['rootPageId' => 1]);

        self::assertNull($this->subject->extract($site, new PageArguments(3, '0', ['article' => '7'])));
    }

    /**
     * @param array<string, mixed> $enhancers
     */
    private function siteWithEnhancers(array $enhancers): Site
    {
        $site = $this->createMock(Site::class);
        $site->method('getConfiguration')->willReturn(['routeEnhancers' => $enhancers]);

        return $site;
    }
}
