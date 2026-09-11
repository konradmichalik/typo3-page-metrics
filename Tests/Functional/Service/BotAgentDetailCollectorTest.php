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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Service;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy\Bucket;
use KonradMichalik\Typo3PageMetrics\Service\BotAgentDetailCollector;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * BotAgentDetailCollectorTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class BotAgentDetailCollectorTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['redirects'];
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private BotAgentDetailCollector $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'languages' => [0 => ['title' => 'English', 'enabled' => true, 'languageId' => 0, 'base' => '/', 'locale' => 'en_US.UTF-8', 'navigationTitle' => 'English', 'flag' => 'us']],
            'errorHandling' => [],
            'routes' => [],
            'pageMetrics' => ['enabled' => true, 'retentionDays' => 5],
        ]);
        $this->subject = $this->get(BotAgentDetailCollector::class);
    }

    public function testFlushWritesBothARollupRowAndAPageScopedRowForAResolvedUrl(): void
    {
        $date = new DateTimeImmutable('2026-10-10');
        $this->subject->add($date, 'https://example.com/about', Bucket::AiCrawler, 'GPTBot', false);

        $this->subject->flush('main', true, []);

        self::assertSame(1, $this->countRows('main', 0, 'ai_crawler', 'GPTBot'));
        self::assertSame(1, $this->countRows('main', 2, 'ai_crawler', 'GPTBot'));
    }

    public function testFlushWritesOnlyTheRollupRowForANonPageUrl(): void
    {
        $date = new DateTimeImmutable('2026-10-10');
        $this->subject->add($date, 'https://example.com/does-not-exist', Bucket::Tool, 'AhrefsBot', false);

        $this->subject->flush('main', true, []);

        self::assertSame(1, $this->countRows('main', 0, 'tool', 'AhrefsBot'));
        self::assertSame(0, $this->countRows('main', 2, 'tool', 'AhrefsBot'));
    }

    public function testFlushWritesNoRowAtAllForAForeignHost(): void
    {
        $date = new DateTimeImmutable('2026-10-10');
        $this->subject->add($date, 'https://not-this-site.example/about', Bucket::Search, 'Googlebot', false);

        $this->subject->flush('main', true, []);

        $total = (int) $this->get(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_typo3pagemetrics_bot_agent_daily')
            ->count('uid')
            ->from('tx_typo3pagemetrics_bot_agent_daily')
            ->executeQuery()
            ->fetchOne();

        self::assertSame(0, $total);
    }

    public function testFlushAggregatesMultipleAddCallsForTheSameIdentityBeforeWriting(): void
    {
        $date = new DateTimeImmutable('2026-10-10');
        $this->subject->add($date, 'https://example.com/about', Bucket::AiCrawler, 'GPTBot', false);
        $this->subject->add($date, 'https://example.com/about', Bucket::AiCrawler, 'GPTBot', true);
        $this->subject->add($date, 'https://example.com/about', Bucket::AiCrawler, 'GPTBot', false);

        $this->subject->flush('main', true, []);

        $row = $this->fetchRow('main', 2, 'ai_crawler', 'GPTBot');
        self::assertSame(3, $row['hits']);
        self::assertSame(1, $row['hits_suspicious']);
    }

    public function testFlushClearsAccumulatedStateSoASecondFlushWithNoNewAddsWritesNothingNew(): void
    {
        $date = new DateTimeImmutable('2026-10-10');
        $this->subject->add($date, 'https://example.com/about', Bucket::AiCrawler, 'GPTBot', false);
        $this->subject->flush('main', true, []);

        $this->subject->flush('main', true, []);

        self::assertSame(1, $this->countRows('main', 2, 'ai_crawler', 'GPTBot'));
    }

    private function countRows(string $siteIdentifier, int $pageUid, string $bucket, string $agent): int
    {
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tx_typo3pagemetrics_bot_agent_daily');

        return (int) $queryBuilder
            ->count('uid')
            ->from('tx_typo3pagemetrics_bot_agent_daily')
            ->where(
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->eq('page_uid', $queryBuilder->createNamedParameter($pageUid)),
                $queryBuilder->expr()->eq('bucket', $queryBuilder->createNamedParameter($bucket)),
                $queryBuilder->expr()->eq('agent', $queryBuilder->createNamedParameter($agent)),
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return array{hits: int, hits_suspicious: int}
     */
    private function fetchRow(string $siteIdentifier, int $pageUid, string $bucket, string $agent): array
    {
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tx_typo3pagemetrics_bot_agent_daily');
        $row = $queryBuilder
            ->select('hits', 'hits_suspicious')
            ->from('tx_typo3pagemetrics_bot_agent_daily')
            ->where(
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->eq('page_uid', $queryBuilder->createNamedParameter($pageUid)),
                $queryBuilder->expr()->eq('bucket', $queryBuilder->createNamedParameter($bucket)),
                $queryBuilder->expr()->eq('agent', $queryBuilder->createNamedParameter($agent)),
            )
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);

        return ['hits' => (int) $row['hits'], 'hits_suspicious' => (int) $row['hits_suspicious']];
    }
}
