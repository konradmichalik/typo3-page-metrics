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
        $this->subject->markImported('matomo', 'main', new DateTimeImmutable('2026-08-01'), new DateRange(new DateTimeImmutable('2026-07-01'), new DateTimeImmutable('2026-08-02')), true);

        $result = $this->subject->getLastImportedDate('matomo', 'main');

        self::assertNotNull($result);
        self::assertSame('2026-08-01', $result->format('Y-m-d'));
    }

    public function testMarkImportedTwiceUpdatesInsteadOfDuplicating(): void
    {
        $this->subject->markImported('matomo', 'main', new DateTimeImmutable('2026-08-01'), new DateRange(new DateTimeImmutable('2026-07-01'), new DateTimeImmutable('2026-08-02')), true);
        $this->subject->markImported('matomo', 'main', new DateTimeImmutable('2026-08-05'), new DateRange(new DateTimeImmutable('2026-08-02'), new DateTimeImmutable('2026-08-06')), true);

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
        $this->subject->markImported('matomo', 'main', new DateTimeImmutable('2026-08-01'), new DateRange(new DateTimeImmutable('2026-07-01'), new DateTimeImmutable('2026-08-02')), true);
        $this->subject->markImported('accesslog', 'main', new DateTimeImmutable('2026-08-02'), new DateRange(new DateTimeImmutable('2026-07-15'), new DateTimeImmutable('2026-08-03')), true);

        self::assertSame('2026-08-01', $this->subject->getLastImportedDate('matomo', 'main')?->format('Y-m-d'));
        self::assertSame('2026-08-02', $this->subject->getLastImportedDate('accesslog', 'main')?->format('Y-m-d'));
    }

    public function testCoverageIsTrackedPerProviderNotPerSite(): void
    {
        // An access log added to a site Matomo has been feeding for a year
        // has its own, much shorter history. A site-wide window would draw
        // that provider's first eleven months as a flat zero.
        $this->subject->markImported('matomo', 'main', new DateTimeImmutable('2026-08-05'), new DateRange(new DateTimeImmutable('2026-07-01'), new DateTimeImmutable('2026-08-06')), true);
        $this->subject->markImported('accesslog', 'main', new DateTimeImmutable('2026-08-09'), new DateRange(new DateTimeImmutable('2026-07-20'), new DateTimeImmutable('2026-08-10')), true);

        self::assertSame('2026-07-01', $this->subject->getCoverage('matomo', 'main')?->from->format('Y-m-d'));
        self::assertSame('2026-07-20', $this->subject->getCoverage('accesslog', 'main')?->from->format('Y-m-d'));
    }

    public function testAnIncrementalRunLeavesAnUnknownWindowUnknown(): void
    {
        // A row written before the covered-window columns existed. The next
        // incremental run only fetched the last day or two, which says
        // nothing about the history before it - writing that as the window
        // would mask every older day as missing, permanently.
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_typo3pagemetrics_import')->insert(
            'tx_typo3pagemetrics_import',
            ['provider' => 'matomo', 'site_identifier' => 'main', 'last_imported_date' => '2026-08-05', 'tstamp' => 0],
        );

        $this->subject->markImported('matomo', 'main', new DateTimeImmutable('2026-08-08'), new DateRange(new DateTimeImmutable('2026-08-06'), new DateTimeImmutable('2026-08-09')), false);

        self::assertNull($this->subject->getCoverage('matomo', 'main'));
        // The resume cursor still advances, so the import itself is unaffected.
        self::assertSame('2026-08-08', $this->subject->getLastImportedDate('matomo', 'main')?->format('Y-m-d'));
    }

    public function testAFullRunEstablishesAWindowThatWasPreviouslyUnknown(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_typo3pagemetrics_import')->insert(
            'tx_typo3pagemetrics_import',
            ['provider' => 'matomo', 'site_identifier' => 'main', 'last_imported_date' => '2026-08-05', 'tstamp' => 0],
        );

        $this->subject->markImported('matomo', 'main', new DateTimeImmutable('2026-08-08'), new DateRange(new DateTimeImmutable('2026-06-10'), new DateTimeImmutable('2026-08-09')), true);

        self::assertSame('2026-06-10', $this->subject->getCoverage('matomo', 'main')?->from->format('Y-m-d'));
    }

    public function testCoverageIncludesTodayRatherThanTheResumeCursor(): void
    {
        // The cursor deliberately lags a day behind so today gets re-fetched;
        // the window does not, or every tile's most recent day would read as
        // missing right after a successful import.
        $today = new DateTimeImmutable('today');
        $this->subject->markImported('matomo', 'main', $today->modify('-1 day'), new DateRange($today->modify('-30 days'), $today), true);

        self::assertSame($today->format('Y-m-d'), $this->subject->getCoverage('matomo', 'main')?->until->format('Y-m-d'));
    }

    public function testCoverageKeepsTheEarliestStartWhenALaterRunPicksUpWhereItStopped(): void
    {
        // An incremental run starts the day after the last one stopped. That
        // says nothing about the history already imported before it, so the
        // stored start must not move forward with it.
        $this->subject->markImported('matomo', 'main', new DateTimeImmutable('2026-08-05'), new DateRange(new DateTimeImmutable('2026-07-01'), new DateTimeImmutable('2026-08-06')), true);
        $this->subject->markImported('matomo', 'main', new DateTimeImmutable('2026-08-09'), new DateRange(new DateTimeImmutable('2026-08-06'), new DateTimeImmutable('2026-08-10')), false);

        $coverage = $this->subject->getCoverage('matomo', 'main');

        self::assertNotNull($coverage);
        self::assertSame('2026-07-01', $coverage->from->format('Y-m-d'));
        // The end still moves forward with the newer run.
        self::assertSame('2026-08-10', $coverage->until->format('Y-m-d'));
    }

    public function testCoverageIsNullForASiteThatHasNeverBeenImported(): void
    {
        self::assertNull($this->subject->getCoverage('matomo', 'main'));
    }

    public function testCoverageIsScopedToItsOwnSite(): void
    {
        $this->subject->markImported('matomo', 'other', new DateTimeImmutable('2026-08-05'), new DateRange(new DateTimeImmutable('2026-07-01'), new DateTimeImmutable('2026-08-06')), true);

        self::assertNull($this->subject->getCoverage('matomo', 'main'));
    }
}
