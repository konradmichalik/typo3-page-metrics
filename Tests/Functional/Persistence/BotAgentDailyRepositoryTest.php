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

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DateRange;
use KonradMichalik\Typo3PageMetrics\Persistence\BotAgentDailyRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * BotAgentDailyRepositoryTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class BotAgentDailyRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private BotAgentDailyRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = $this->get(BotAgentDailyRepository::class);
    }

    public function testUpsertInsertsANewRow(): void
    {
        $date = new DateTimeImmutable('2026-10-10');
        $this->subject->upsert('main', 2, 0, 'ai_crawler', 'GPTBot', $date, 5, 1);

        self::assertSame(['hits' => 5, 'hits_suspicious' => 1], $this->fetchRow('main', 2, 'ai_crawler', 'GPTBot'));
    }

    public function testUpsertReplacesRatherThanAddsOnASecondCallForTheSameIdentity(): void
    {
        $date = new DateTimeImmutable('2026-10-10');
        $this->subject->upsert('main', 2, 0, 'ai_crawler', 'GPTBot', $date, 5, 1);
        $this->subject->upsert('main', 2, 0, 'ai_crawler', 'GPTBot', $date, 9, 3);

        self::assertSame(['hits' => 9, 'hits_suspicious' => 3], $this->fetchRow('main', 2, 'ai_crawler', 'GPTBot'));
    }

    public function testUpsertKeepsRowsWithDifferentAgentsSeparate(): void
    {
        $date = new DateTimeImmutable('2026-10-10');
        $this->subject->upsert('main', 2, 0, 'ai_crawler', 'GPTBot', $date, 5, 0);
        $this->subject->upsert('main', 2, 0, 'ai_crawler', 'ClaudeBot', $date, 3, 0);

        self::assertSame(['hits' => 5, 'hits_suspicious' => 0], $this->fetchRow('main', 2, 'ai_crawler', 'GPTBot'));
        self::assertSame(['hits' => 3, 'hits_suspicious' => 0], $this->fetchRow('main', 2, 'ai_crawler', 'ClaudeBot'));
    }

    public function testUpsertKeepsThePageZeroRollupRowSeparateFromAPageScopedRow(): void
    {
        $date = new DateTimeImmutable('2026-10-10');
        $this->subject->upsert('main', 0, 0, 'ai_crawler', 'GPTBot', $date, 20, 0);
        $this->subject->upsert('main', 2, 0, 'ai_crawler', 'GPTBot', $date, 5, 0);

        self::assertSame(['hits' => 20, 'hits_suspicious' => 0], $this->fetchRow('main', 0, 'ai_crawler', 'GPTBot'));
        self::assertSame(['hits' => 5, 'hits_suspicious' => 0], $this->fetchRow('main', 2, 'ai_crawler', 'GPTBot'));
    }

    public function testAgentSumsGroupsHitsPerAgentAcrossDaysAndPages(): void
    {
        $this->subject->upsert('main', 2, 0, 'ai_crawler', 'GPTBot', new DateTimeImmutable('2026-10-10'), 5, 0);
        $this->subject->upsert('main', 2, 0, 'ai_crawler', 'GPTBot', new DateTimeImmutable('2026-10-11'), 7, 0);
        $this->subject->upsert('main', 3, 0, 'ai_crawler', 'GPTBot', new DateTimeImmutable('2026-10-11'), 1, 0);
        $this->subject->upsert('main', 2, 0, 'ai_crawler', 'ClaudeBot', new DateTimeImmutable('2026-10-10'), 20, 0);

        self::assertSame(
            ['ai_crawler' => ['ClaudeBot' => 20, 'GPTBot' => 13]],
            $this->subject->agentSums([2, 3], 0, ['ai_crawler'], $this->range('2026-10-10', '2026-10-11')),
        );
    }

    public function testAgentSumsCountsOnlyRowsInsideTheRange(): void
    {
        $this->subject->upsert('main', 2, 0, 'ai_crawler', 'GPTBot', new DateTimeImmutable('2026-10-09'), 100, 0);
        $this->subject->upsert('main', 2, 0, 'ai_crawler', 'GPTBot', new DateTimeImmutable('2026-10-10'), 5, 0);
        $this->subject->upsert('main', 2, 0, 'ai_crawler', 'GPTBot', new DateTimeImmutable('2026-10-12'), 100, 0);

        self::assertSame(
            ['ai_crawler' => ['GPTBot' => 5]],
            $this->subject->agentSums([2], 0, ['ai_crawler'], $this->range('2026-10-10', '2026-10-11')),
        );
    }

    public function testAgentSumsKeepsBucketsApartAndReturnsOnlyTheRequestedOnes(): void
    {
        $date = new DateTimeImmutable('2026-10-10');
        $this->subject->upsert('main', 2, 0, 'ai_crawler', 'GPTBot', $date, 5, 0);
        $this->subject->upsert('main', 2, 0, 'ai_chatbot', 'ChatGPT-User', $date, 3, 0);
        $this->subject->upsert('main', 2, 0, 'search', 'Googlebot', $date, 99, 0);

        self::assertSame(
            ['ai_chatbot' => ['ChatGPT-User' => 3], 'ai_crawler' => ['GPTBot' => 5]],
            $this->subject->agentSums([2], 0, ['ai_chatbot', 'ai_crawler'], $this->range('2026-10-10', '2026-10-10')),
        );
    }

    public function testAgentSumsIgnoresRowsOfAnotherLanguage(): void
    {
        $date = new DateTimeImmutable('2026-10-10');
        $this->subject->upsert('main', 2, 0, 'ai_crawler', 'GPTBot', $date, 5, 0);
        $this->subject->upsert('main', 2, 1, 'ai_crawler', 'GPTBot', $date, 100, 0);

        self::assertSame(
            ['ai_crawler' => ['GPTBot' => 5]],
            $this->subject->agentSums([2], 0, ['ai_crawler'], $this->range('2026-10-10', '2026-10-10')),
        );
    }

    public function testAgentSumsLeavesThePageZeroRollupOutOfAPageScopedSum(): void
    {
        $date = new DateTimeImmutable('2026-10-10');
        $this->subject->upsert('main', 0, 0, 'ai_crawler', 'GPTBot', $date, 20, 0);
        $this->subject->upsert('main', 2, 0, 'ai_crawler', 'GPTBot', $date, 5, 0);

        self::assertSame(
            ['ai_crawler' => ['GPTBot' => 5]],
            $this->subject->agentSums([2], 0, ['ai_crawler'], $this->range('2026-10-10', '2026-10-10')),
        );
    }

    public function testAgentSumsReturnsAnEmptyArrayWhereNoRowsMatch(): void
    {
        $this->subject->upsert('main', 2, 0, 'ai_crawler', 'GPTBot', new DateTimeImmutable('2026-10-10'), 5, 0);

        self::assertSame([], $this->subject->agentSums([9], 0, ['ai_crawler'], $this->range('2026-10-10', '2026-10-10')));
        self::assertSame([], $this->subject->agentSums([], 0, ['ai_crawler'], $this->range('2026-10-10', '2026-10-10')));
        self::assertSame([], $this->subject->agentSums([2], 0, [], $this->range('2026-10-10', '2026-10-10')));
    }

    private function range(string $from, string $until): DateRange
    {
        return new DateRange(new DateTimeImmutable($from), new DateTimeImmutable($until));
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
