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
use KonradMichalik\Typo3PageMetrics\Persistence\ImportStateRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * ImportStateRepositoryTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ImportStateRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private ImportStateRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = $this->get(ImportStateRepository::class);
    }

    public function testGetLastImportedDateReturnsNullWhenNeverImported(): void
    {
        self::assertNull($this->subject->getLastImportedDate('matomo', 'main'));
    }

    public function testMarkImportedThenGetLastImportedDateReturnsThatDate(): void
    {
        $this->subject->markImported('matomo', 'main', new DateTimeImmutable('2026-08-01'));

        $result = $this->subject->getLastImportedDate('matomo', 'main');

        self::assertNotNull($result);
        self::assertSame('2026-08-01', $result->format('Y-m-d'));
    }

    public function testMarkImportedTwiceUpdatesInsteadOfDuplicating(): void
    {
        $this->subject->markImported('matomo', 'main', new DateTimeImmutable('2026-08-01'));
        $this->subject->markImported('matomo', 'main', new DateTimeImmutable('2026-08-05'));

        self::assertSame('2026-08-05', $this->subject->getLastImportedDate('matomo', 'main')?->format('Y-m-d'));

        $rowCount = (int) $this->get(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_typo3pagemetrics_import')
            ->count('uid')
            ->from('tx_typo3pagemetrics_import')
            ->executeQuery()
            ->fetchOne();

        self::assertSame(1, $rowCount);
    }

    public function testTracksStatePerProviderAndSiteIndependently(): void
    {
        $this->subject->markImported('matomo', 'main', new DateTimeImmutable('2026-08-01'));
        $this->subject->markImported('accesslog', 'main', new DateTimeImmutable('2026-08-02'));

        self::assertSame('2026-08-01', $this->subject->getLastImportedDate('matomo', 'main')?->format('Y-m-d'));
        self::assertSame('2026-08-02', $this->subject->getLastImportedDate('accesslog', 'main')?->format('Y-m-d'));
    }
}
