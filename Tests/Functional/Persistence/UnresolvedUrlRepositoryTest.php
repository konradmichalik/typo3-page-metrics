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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Persistence;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\UnresolvedReason;
use KonradMichalik\Typo3PageMetrics\Persistence\UnresolvedUrlRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * UnresolvedUrlRepositoryTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class UnresolvedUrlRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private UnresolvedUrlRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = $this->get(UnresolvedUrlRepository::class);
    }

    public function testRecordInsertsARow(): void
    {
        $this->subject->record('matomo', new DateTimeImmutable('2026-08-01'), 'https://example.com/gone', 'matomo.pageviews', UnresolvedReason::Unknown, 5, 100);

        self::assertSame(1, $this->countRows());
    }

    public function testRecordUpdatesInsteadOfDuplicatingSameUrlAndMetric(): void
    {
        $date = new DateTimeImmutable('2026-08-01');
        $this->subject->record('matomo', $date, 'https://example.com/gone', 'matomo.pageviews', UnresolvedReason::Unknown, 5, 100);
        $this->subject->record('matomo', $date, 'https://example.com/gone', 'matomo.pageviews', UnresolvedReason::Unknown, 9, 100);

        self::assertSame(1, $this->countRows());
    }

    public function testTrimsToCapKeepingHighestValueRows(): void
    {
        $date = new DateTimeImmutable('2026-08-01');
        $this->subject->record('matomo', $date, 'https://example.com/a', 'matomo.pageviews', UnresolvedReason::Unknown, 10, 2);
        $this->subject->record('matomo', $date, 'https://example.com/b', 'matomo.pageviews', UnresolvedReason::Unknown, 30, 2);
        $this->subject->record('matomo', $date, 'https://example.com/c', 'matomo.pageviews', UnresolvedReason::Unknown, 20, 2);

        self::assertSame(2, $this->countRows());

        $remainingUrls = $this->get(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_pagemetrics_unresolved')
            ->select('url')
            ->from('tx_pagemetrics_unresolved')
            ->executeQuery()
            ->fetchFirstColumn();

        sort($remainingUrls);
        self::assertSame(['https://example.com/b', 'https://example.com/c'], $remainingUrls);
    }

    public function testCountForSiteCountsOnlyUrlsOnThatHost(): void
    {
        $date = new DateTimeImmutable('today');
        $this->subject->record('matomo', $date, 'https://example.com/gone-a', 'matomo.pageviews', UnresolvedReason::Unknown, 1, 100);
        $this->subject->record('matomo', $date, 'https://example.com/gone-b', 'matomo.pageviews', UnresolvedReason::Unknown, 1, 100);
        $this->subject->record('matomo', $date, 'https://other-host.example/gone', 'matomo.pageviews', UnresolvedReason::Unknown, 1, 100);

        self::assertSame(2, $this->subject->countForSite('example.com', $date->modify('-30 days')));
    }

    public function testCountForSiteExcludesEntriesBeforeSince(): void
    {
        $this->subject->record('matomo', new DateTimeImmutable('2026-01-01'), 'https://example.com/old', 'matomo.pageviews', UnresolvedReason::Unknown, 1, 100);

        self::assertSame(0, $this->subject->countForSite('example.com', new DateTimeImmutable('2026-06-01')));
    }

    private function countRows(): int
    {
        return (int) $this->get(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_pagemetrics_unresolved')
            ->count('uid')
            ->from('tx_pagemetrics_unresolved')
            ->executeQuery()
            ->fetchOne();
    }
}
